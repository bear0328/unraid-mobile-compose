<?php

declare(strict_types=1);

/**
 * unraid-mobile Compose API(续 47 2026-07-19)
 *
 * 正本位置: /boot/config/plugins/unraid-mobile/api.php (flash 盘,持久,全 unRAID 路径统一)
 * 执行位置: /usr/local/emhttp/plugins/compose.manager/api.php
 *           — 宿主 php.ini 设 doc_root=/usr/local/emhttp,php-fpm 只执行 doc_root 内的脚本;
 *           该目录是 tmpfs,重启丢失,由 /boot/config/go 钩子从正本 cp 恢复
 *           (install-compose-api.sh 幂等安装以上全部)
 * 服务通道: unraid-mobile 容器 nginx /compose-api/ (fastcgi) → 宿主 php-fpm unix socket
 *           (/var/run/php-fpm.sock,root 运行) → 本文件。
 *           绕开 unRAID webGui 的会话认证(auth-request.php 不白名单任何插件端点)。
 *           注意 nginx 必须同时传匹配的 SCRIPT_NAME(doc_root 相对路径),否则 php-fpm
 *           按 doc_root+SCRIPT_NAME 解析,无视 SCRIPT_FILENAME,报 ENOENT。
 *
 * 鉴权: X-Api-Key header 与 /boot/config/plugins/unraid-mobile/apikey 比对
 *       (与 GraphQL 同一个密钥,app 端零额外配置;flash 盘 root 600,不在 web 服务路径)。
 *       Pro 路由另验 X-Unraid-Mobile-License;完整 key 不落盘/不记日志,
 *       首次由中心确认绑机后仅在本机保存 key/guid 的 SHA-256 凭证,后续离线可用。
 *   PUT  {action:licenseactivate}          激活时同步本机后端授权凭证(仍需 X-Api-Key)。
 *       【续 60】文件格式 `sha256:<hex>` = 存 key 的哈希,不明文存 key —— flash 备份/诊断包
 *       外泄不泄 key 本身;无前缀的旧明文格式自动兼容(重跑安装脚本即升级为哈希)。
 *
 * 端点:
 *   GET  ?action=capabilities             宿主能力自检:当前宿主后端版本、页内同步能力、Unraid/PHP 版本、api.php 正本与执行位置
 *                                         sha256、go 恢复错误日志(restore-error.log)、各 Unraid
 *                                         内部依赖可用性。只读、不执行外部命令、不联网;
 *                                         前端按 deps 降级,OS 升级后不必逐项人工排查
 *   GET  ?action=cputemp                    CPU 温度(续 51:直读 /sys/class/hwmon CPU 传感器,
 *                                           纯内核 sysfs 不碰 smartctl/块设备,全盘 standby 不唤盘。
 *                                           这是续 46.5 "GraphQL temperature 唤盘红线"的安全替代)
 *   GET  ?action=networkinfo                网卡状态与累积字节(直读 /proc/net/dev + /sys/class/net,
 *                                           不执行外部命令,计数字符串防 64 位精度损失)
 *   GET  ?action=systemmetrics              CPU RAPL 能量、分类内存与 Intel/NVIDIA GPU 只读指标
 *   GET  ?action=vminfo&vm=UUID             VM 静态配置(libvirt XML,旧名称寻址兼容)
 *   GET  ?action=vmstats&vm=UUID            VM CPU/内存/磁盘/网络原始计数器(domstats)
 *   GET  ?action=vmlog&vm=UUID&offset=N     QEMU/libvirt 日志字节游标增量读取(固定目录)
 *   GET  ?action=updates                    有更新的镜像 ref 列表(合并 webGui 主缓存与
 *                                           Compose 补充缓存,剥 library/ 前缀;补充缓存
 *                                           过期时后台补扫,请求不同步等待 Registry)
 *   GET  ?action=containericons             全部容器名 → 图标引用(手工优先,模板/CA/索引兜底)
 *   GET  ?action=iconfile&id=X              已上传图标(鉴权二进制响应,仅内容哈希文件)
 *   PUT  {action:seticon,target,name,icon|data}
 *                                         docker/compose 手工 HTTPS 图标、PNG/JPEG/WebP 上传;
 *                                         icon:null 恢复自动匹配,不修改模板或重建容器
 *   GET  ?action=plugins                    已安装插件与本地检查缓存(不联网)
 *   GET  ?action=pluginjob&id=X             插件异步任务状态/进度/截尾日志
 *   GET  ?action=caapps&refresh=0|1         CA 应用商店目录(只读):官方 feed 白名单字段 + /tmp 短缓存
 *   GET  ?action=catemplate&url=X           feed 白名单内 Docker 模板;mask 配置不回传默认值
 *   GET  ?action=cajob&id=X                 CA 安装任务 running/log/result(严格任务 ID)
 *   GET  ?action=dockertemplate&name=X       普通容器 v2 用户模板(掩码值不回传,带 revision)
 *   PUT  {action:dockeredit,name,revision,...配置}
 *                                         原子更新模板并异步重建;缺镜像才拉取,保持原运行状态
 *   GET  ?action=dockereditjob&id=X          参数编辑任务 running/log/result(严格任务 ID)
 *   PUT  {action:dockerupdate,name,expectedId,requestId}
 *                                         普通容器异步更新;服务端复核 ID/Compose/模板并支持幂等恢复
 *   GET  ?action=dockerlog&name=X&requestId=X
 *                                         更新任务 jobId/state/running/log/result;异常退出不冒充确定失败
 *   PUT  {action:hostupdate,targetVersion,requestId}
 *                                         固定官方 tag 的宿主后端异步同步;全量校验、保留凭据、失败回滚
 *   GET  ?action=hostupdatelog&requestId=X  宿主同步任务状态/日志;支持幂等恢复与 409 附着
 *   GET  ?action=externalprojects          非 compose.manager 的 Compose 项目与容器名
 *   PUT  {action:externalupdate,name,expectedId,requestId}
 *                                         外部 Compose 项目异步 pull + 重建;路径只取实时容器标签
 *   GET  ?action=externalupdatelog&requestId=X  或 &project=X
 *                                         外部更新任务状态;支持 requestId 恢复与项目级附着
 *   PUT  {action:cainstall,templateUrl,values}
 *                                          重新校验 feed 与模板,仅新容器;以 CA 模板直写用户模板
 *                                          my-<name>.xml(不再伪造 $_POST/include CreateDocker.php),
 *                                          独立 worker 先 docker pull 再调官方 update_container
 *                                          创建并启动,最终按同名容器存在判成功
 *   PUT  {action: plugincheck|pluginupdateall}
 *   PUT  {action: pluginupdate|pluginremove, name}
 *   PUT  {action: plugininstall, url}       均返回 jobId,由独立 worker 串行调用官方 plugin CLI
 *   GET  ?action=list                       栈列表(名称/状态/autostart/last_result)
 *   GET  ?action=get&name=X                 栈详情(compose.yaml/override/log)
 *   GET  ?action=log&name=X                 操作日志 + 是否有异步任务在跑
 *   PUT  {action: up|down|restart, name}    同步执行(快操作) — Content-Type: application/json
 *   PUT  {action: pull|rebuild, name}       异步执行(慢操作,前端轮询 log)
 *   PUT  {action: autostart, name, value}   value = "true"|"false"
 *   PUT  {action: diskwake}               显式唤醒已确认休眠的阵列磁盘(HDD/SSD)(判定只读 emhttp
 *                                           状态文件,起转走官方 emcmd cmdSpinup;不用 SMART、不读块设备)
 *   PUT  {action: serverpower, operation: reboot|shutdown}
 *                                          服务器重启/关机(高危不可逆):严格白名单,除 action/operation
 *                                          外任何额外参数都拒绝;审计先写,再用 nohup 异步下发官方
 *                                          WebGUI 同款 /sbin/reboot -n 或 /sbin/poweroff -n,
 *                                          立即返回 {accepted:true,operation},不等待断线
 *   PUT  ?name=X   body=compose.yaml 内容    tmp+校验+rename 原子写入,失败原文件不动(留 bak 双保险)
 *
 * 注意: 写操作必须用 PUT 不能用 POST — php.ini auto_prepend_file(local_prepend.php)
 *       对所有 POST 强制 webGui CSRF 校验,无 token 静默 exit(空 200);PUT 不受检查。
 */

// 与 package.json 同步；capabilities 报告当前执行的宿主后端版本,不能据此推断容器镜像版本。
const APP_VERSION = '1.5.14';
const PROJECTS_DIR = '/boot/config/plugins/compose.manager/projects';
// 【续 49.2 2026-07-19】key 文件挪到 flash 盘统一路径 — 消灭最后一个 per-user 路径
// (appdata 目录名因用户/容器名而异,/boot/config 全 unRAID 一致,api.php 由此零参数化)
const KEY_FILE = '/boot/config/plugins/unraid-mobile/apikey';
const LOG_TAIL_BYTES = 65536;
const DOCKER = '/usr/bin/docker';
const PATH_ENV = 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
// 【续 50 D3-3】审计日志:敏感动作成败各一行;超过 1MB 轮转为 .old(只留一代,简单)
const AUDIT_LOG = '/boot/config/plugins/unraid-mobile/audit.log';
const AUDIT_MAX_BYTES = 1048576;
// 主缓存由 webGui dockerupdate 维护;补充缓存覆盖其未跟踪的 Compose 镜像。
const UPDATE_STATUS_FILE = '/var/lib/docker/unraid-update-status.json';
const UPDATE_EXTRA_STATUS_FILE = '/tmp/unraid-mobile-update-status.json';
const UPDATE_SCAN_LOCK_FILE = '/tmp/unraid-mobile-update-scan.lock';
const UPDATE_SCAN_INTERVAL = 86400;
const UPDATE_SCAN_RETRY_INTERVAL = 300;

// 【续 118】缓存池文件搜索:只读 SSD cache 池,不碰 /mnt/user、/mnt/disk*(唤盘红线)
const CACHE_POOL_DIR = '/mnt/cache';
// 【续 120】全盘搜索:/mnt/user FUSE 联合视图(cache+全部阵列盘),休眠盘会被唤醒。
// 仅用户显式二次确认后由前端传 scope=all 触发,写 audit 留痕;不碰 /mnt/disk*、/mnt/user0
const USER_SHARES_DIR = '/mnt/user';
const SEARCH_MAX_RESULTS = 200;
const SEARCH_TIMEOUT_SEC = 15;
// 全盘搜索多盘顺序起转可能耗时 1-2 分钟,timeout 放宽到 120s
const SEARCH_ALL_TIMEOUT_SEC = 120;
// 【续 123】全盘文件名索引:cron 低峰(或 PUT reindex 显式)跑 update-file-index.sh
// 扫 /mnt/user 落到 prod appdata 目录(物理在 cache 池)的 TSV;scope=index 搜索只
// grep 该文件(SSD 常转),阵列休眠盘零接触。索引构建本身唤盘,故只允许计划任务/显式触发
const INDEX_FILE = '/mnt/cache/appdata/unraid-mobile/file-index.tsv';
const INDEX_BUILDER = '/boot/config/plugins/unraid-mobile/update-file-index.sh';
// 【续 130】flag 语义收窄:只管「每日自动构建」(cron 注册/go 钩子以此为条件,
// 设置页 PUT indexcfg 控制),不再是功能开关 —— scope=index 搜索只读 cache 池索引文件,
// 零唤盘,无需开关;索引不存在自然 503。手动 reindex 任何时候可用(本就不查 flag)。
// 安装默认不开自动构建(不再 touch flag),用户需要每天在设置页自行打开
const INDEX_ENABLED_FLAG = '/boot/config/plugins/unraid-mobile/index-enabled';
// 【续 125】定时索引整点(0-23,缺省 3),设置页可配
const INDEX_HOUR_FILE = '/boot/config/plugins/unraid-mobile/index-hour';
// cron 注册/摘除脚本(读 flag + hour;install 脚本安装,go 钩子重启也调它)
const INDEX_CRON_REGISTER = '/boot/config/plugins/unraid-mobile/register-index-cron.sh';
// 【续 147】docker 容器更新(异步带 pull 进度日志):工作目录在 tmpfs,重启自清零 flash 磨损
const DOCKER_UPDATE_DIR = '/tmp/unraid-mobile-dockerupdate';
const DOCKER_UPDATE_SCRIPT = '/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/update_container';
const DOCKER_UPDATE_SETSID = '/usr/bin/setsid';
const DOCKER_UPDATE_START_GRACE_SEC = 30;
const EXTERNAL_UPDATE_DIR = '/tmp/unraid-mobile-externalupdate';
// 宿主后端自升级:独立 tmpfs 任务,下载固定 tag 发布包并全量校验后调用既有幂等安装器。
const HOST_UPDATE_DIR = '/tmp/unraid-mobile-hostupdate';
const HOST_UPDATE_REPO = 'https://raw.githubusercontent.com/bear0328/unraid-mobile-compose';
const TEMPLATE_USER_DIR = '/boot/config/plugins/dockerMan/templates-user';
const PLUGIN_WORK_DIR = '/tmp/unraid-mobile-plugins';
const PLUGIN_WORKER = '/usr/local/emhttp/plugins/compose.manager/plugin-worker.php';
const PLUGIN_INSTALLED_DIR = '/var/log/plugins';
// ---------- CA 应用商店(Community Applications feed)只读代理 ----------
// 只读语义:拉官方 feed → 白名单归一化 → 本地缓存。不执行 feed 里的任何脚本,
// 不安装任何应用(安装仍走 Unraid 官方 plugin CLI / Docker 官方路径,不在本端点内)。
const CA_APPS_FEED_URL = 'https://ca.unraid.net/assets/feed/applicationFeed.json';
// 缓存只允许落在 /tmp/unraid-mobile-plugins/(tmpfs,重启自清零,不占 flash)
const CA_APPS_CACHE_FILE = PLUGIN_WORK_DIR . '/caapps.json';
// 短 TTL:避免每次刷新都拉远端约 20MB 的 feed
const CA_APPS_CACHE_TTL = 1800;
// 大小上限 32MB(实测 feed 约 20MB;超出即判失败,不吃磁盘也不吃内存)
const CA_APPS_MAX_BYTES = 33554432;
const CA_APPS_CONNECT_TIMEOUT_SEC = 10;
const CA_APPS_TOTAL_TIMEOUT_SEC = 60;
const CA_TEMPLATE_MAX_BYTES = 2097152;
const CA_INSTALL_LOCK = PLUGIN_WORK_DIR . '/cainstall.lock';
// 普通容器参数编辑:独立任务与容器级锁;原模板/日志只在 tmpfs,重启自清。
const DOCKER_EDIT_DIR = '/tmp/unraid-mobile-dockeredit';
const DOCKER_EDIT_HELPER = '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php';
// 描述纯文本长度上限
const CA_APPS_DESC_MAX = 1200;
// 响应字段白名单:只有这些键能出现在 apps[] 里
const CA_APPS_FIELDS = [
    'id', 'name', 'description', 'icon', 'categories', 'author', 'repository',
    'pluginUrl', 'templateUrl', 'installed', 'type', 'support', 'project',
];
// 已安装 Docker 模板目录(只读扫描,用于保守判断安装态)
const DOCKER_TEMPLATE_COMMUNITY_DIR = '/boot/config/plugins/dockerMan/templates-community';
// 手工图标持久化:独立配置,不写 Docker 模板或 Compose 文件;上传仅由显式 PUT 触发。
const CONTAINER_ICON_CONFIG = '/boot/config/plugins/unraid-mobile/container-icons.json';
const CONTAINER_ICON_FILES_DIR = '/boot/config/plugins/unraid-mobile/icons';
const CONTAINER_ICON_MAX_BYTES = 2097152;
// 服务器电源(高危不可逆):operation → 固定命令的严格白名单表。
// 命令与官方 WebGUI 同款(/sbin/reboot -n、/sbin/poweroff -n);用户输入只做表键查表,
// 绝不拼进 shell 命令行 —— 表里没有的 operation 一律拒绝。
const SERVER_POWER_COMMANDS = [
    'reboot' => '/sbin/reboot -n',
    'shutdown' => '/sbin/poweroff -n',
];

// 磁盘唤醒(显式触发):判定只读 emhttp 状态文件,不跑 SMART/hdparm/dd/mdcmd、不读块设备,
// 所以「读状态」这一步本身不会唤醒任何盘;只有用户显式调用才发起起转。
const DISKS_INI_FILE = '/usr/local/emhttp/state/disks.ini';
const ARRAY_VAR_FILE = '/var/local/emhttp/var.ini';
// Pro 后端二次授权:完整 key 仅随本机请求进入 PHP,不写日志/不落盘;
// 中心与本机持久凭证都只保存 SHA-256(key) + SHA-256(flashGuid)。
const LICENSE_PUBLIC_KEY_B64U = 'BBz7qrzF-TaE-alABvbNyn4bVGEmpLZtW7B-_V0yslZ8wglJqV4zhEB3sXgp6kOe8f4E_zfm8rFJU1AvkA2X3K8';
const LICENSE_BIND_URL = 'https://mobile.bearsound.top:16666/license-api/v1/license/bind';
const LICENSE_RECEIPT_FILE = '/boot/config/plugins/unraid-mobile/license-backend-binding.json';
const PRO_GET_ACTIONS = [
    'cputemp', 'vminfo', 'vmstats', 'vmlog',
    'plugins', 'pluginjob', 'caapps', 'catemplate', 'cajob',
    'dockertemplate', 'dockereditjob', 'list', 'get', 'log', 'dockerlog',
    'externalprojects', 'externalupdatelog', 'hostupdatelog',
];
const PRO_PUT_ACTIONS = [
    'plugincheck', 'pluginupdate', 'pluginremove', 'pluginupdateall', 'plugininstall',
    'cainstall', 'dockeredit', 'autostart', 'indexcfg', 'reindex',
    'dockerupdate', 'externalupdate', 'hostupdate', 'serverpower', 'up', 'down', 'restart', 'pull', 'rebuild',
];
const DISK_WAKE_LOCK_FILE = '/tmp/unraid-mobile-diskwake.lock';
const EMCMD = '/usr/local/sbin/emcmd';
// 并行提交后最多等 30s(每秒重读一次状态文件),整体请求上限 45s
const DISK_WAKE_WAIT_SEC = 30;
const DISK_WAKE_TIME_LIMIT_SEC = 45;

header('Content-Type: application/json; charset=utf-8');

// 【续 113】JSON_INVALID_UTF8_SUBSTITUTE:compose 日志含 ANSI/Braille 进度符,
// tailFile 按字节截断可能切断多字节字符 → 无效 UTF-8 → json_encode 返回 false
// → echo false = 空 body → 前端报 "HTTP 200"(Teslamate 111KB 日志实证)。
// 无效字符替换为 U+FFFD;再加 false 兜底,任何编码失败都不许输出空 body。
function ok(mixed $data): void
{
    $json = json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json !== false ? $json : '{"ok":false,"error":"响应 JSON 编码失败"}';
    exit;
}

function fail(int $code, string $msg): void
{
    http_response_code($code);
    $json = json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json !== false ? $json : '{"ok":false,"error":"响应 JSON 编码失败"}';
    exit;
}

// ---------- 鉴权 ----------
// 【续 100】认证失败审计限流:原每次 401 都 file_put_contents 到 flash 盘
// (AUDIT_LOG 在 /boot/config),公网撞 key 流量会磨损 flash + 触发 1MB 轮转 churn。
// 现改为:① 每次都 error_log 到 php-fpm 日志(tmpfs,零 flash 磨损,兜底可溯);
// ② flash 审计行同分钟最多写一条(戳记文件在 /tmp,也不占 flash)
function auditAuthFail(): void
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
    error_log('unraid-mobile compose-api: auth fail from ' . $ip);
    $stamp = '/tmp/unraid-mobile-authfail.stamp';
    $last = @filemtime($stamp);
    $now = time();
    if ($last !== false && $now - $last < 60) {
        return; // 同分钟内已写过 flash 审计
    }
    @touch($stamp);
    audit('auth', '-', 'fail');
}

$stored = trim((string) @file_get_contents(KEY_FILE));
if ($stored === '') {
    fail(503, 'compose API 未配置: key 文件缺失,请运行 install-compose-api.sh');
}
$provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!is_string($provided) || $provided === '') {
    // 【续 88 2026-08-08】认证失败也进审计日志(此前只记动作成败,401 无痕迹)
    // 【续 100】改走 auditAuthFail():php-fpm error_log 兜底 + flash 审计同分钟限流
    auditAuthFail();
    fail(401, '未授权: X-Api-Key 无效');
}
// 【续 60】文件格式自描述前缀: `sha256:<64hex>` = 哈希存储(新装),否则 = 旧明文(兼容)。
// 不能用内容嗅探(unRAID key 本身就是 64 hex,会误判);比对必须是 stored vs hash(provided)。
// unRAID API key 本身是高熵随机串,无盐快哈希即可(无彩虹表/爆破场景)。
if (str_starts_with($stored, 'sha256:')) {
    $ok = hash_equals(substr($stored, 7), hash('sha256', $provided));
} else {
    $ok = hash_equals($stored, $provided);
}
if (!$ok) {
    auditAuthFail();
    fail(401, '未授权: X-Api-Key 无效');
}

// ---------- 工具 ----------

// 【续 50 D3-3】审计日志:每个敏感动作成功/失败各追加一行(fail()/ok() 会 exit,须先审计)
function audit(string $action, string $stack, string $result): void
{
    $size = @filesize(AUDIT_LOG);
    if ($size !== false && $size > AUDIT_MAX_BYTES) {
        @rename(AUDIT_LOG, AUDIT_LOG . '.old');
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '-');
    $line = sprintf("[%s] action=%s stack=%s result=%s ip=%s\n", date('c'), $action, $stack, $result, $ip);
    @file_put_contents(AUDIT_LOG, $line, FILE_APPEND | LOCK_EX);
}

/** 校验栈名(防路径穿越)并确认目录存在 */
function validName(mixed $name): string
{
    $name = (string) $name;
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $name)) {
        fail(400, '非法栈名');
    }
    if (!is_dir(PROJECTS_DIR . '/' . $name)) {
        fail(404, '栈不存在: ' . $name);
    }
    return $name;
}

/** docker compose 项目名归一化(目录名 → 小写、去非法字符),与 compose ls 的 Name 对应 */
function projectName(string $dirName): string
{
    return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($dirName));
}

function composeFileCandidates(): array
{
    return ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];
}

function overrideFileCandidates(): array
{
    return [
        'compose.override.yaml',
        'compose.override.yml',
        'docker-compose.override.yaml',
        'docker-compose.override.yml',
    ];
}

/** 返回目录下第一个存在的候选文件名,没有则 null */
function firstExisting(string $dir, array $candidates): ?string
{
    foreach ($candidates as $c) {
        if (is_file($dir . '/' . $c)) {
            return $c;
        }
    }
    return null;
}

/** `docker compose ls` 的 project => row 映射(只取正在运行/退出的栈) */
function composeLsMap(): array
{
    $out = shell_exec(PATH_ENV . ' ' . DOCKER . ' compose ls --format json 2>/dev/null');
    $map = [];
    $rows = json_decode((string) $out, true);
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (isset($row['Name'])) {
                $map[(string) $row['Name']] = $row;
            }
        }
    }
    return $map;
}

/**
 * 【续 70 2026-07-28】project => [容器名] 精确归属映射(靠 docker label,
 * 不靠名字启发式)。背景:自定义 container_name(如项目 ms-go / 容器 msgo)
 * 会让前端「更新」徽章的前缀匹配失配。单次 docker ps -a(含停止的容器),
 * 失败返回空数组静默降级(前端回退前缀启发式)。
 */
function projectContainersMap(?array &$serviceMap = null, ?array &$workingDirMap = null, bool $strict = false): array
{
    $out = [];
    $code = 0;
    exec(
        PATH_ENV . ' ' . DOCKER
        . ' ps -a --filter label=com.docker.compose.project'
        . " --format '{{.Names}}|{{.Label \"com.docker.compose.project\"}}|{{.Label \"com.docker.compose.service\"}}|{{.Label \"com.docker.compose.project.working_dir\"}}' 2>/dev/null",
        $out,
        $code
    );
    // 普通列表保留旧静默降级；更新前归属确认必须区分“无项目”和“发现失败”。
    if ($strict && $code !== 0) fail(503, '无法读取 Compose 容器归属,已取消更新准备');
    $map = [];
    $serviceMap = [];
    $workingDirMap = [];
    foreach ($out as $line) {
        $parts = explode('|', trim($line), 4);
        if (count($parts) === 4 && $parts[0] !== '' && $parts[1] !== '') {
            $map[$parts[1]][] = $parts[0];
            if ($parts[2] !== '') {
                $serviceMap[$parts[1]][$parts[0]] = $parts[2];
            }
            if ($parts[3] !== '') {
                $workingDirMap[$parts[1]][$parts[0]] = $parts[3];
            }
        }
    }
    return $map;
}

function readJsonFile(string $path): ?array
{
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

// ---------- Pro 后端二次授权 ----------
// 与 Web/Swift 使用同一 ECDSA P-256 SHA-256 公钥;私钥只存在签发机。
// 首次或升级后首次后端授权需联网确认中心绑定;确认后本机凭证离线有效。
// 凭证只是后端缓存,不能替代每次请求的原始 key 验签;过期 key 仍会被拒绝。
function licenseBase64UrlDecode(string $value): ?string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value) || strlen($value) % 4 === 1) {
        return null;
    }
    $base64 = strtr($value, '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
    $decoded = base64_decode($base64, true);
    return is_string($decoded) ? $decoded : null;
}

function licenseRawSignatureToDer(string $raw): ?string
{
    // gen-license.mjs / WebCrypto / CryptoKit 均使用 IEEE-P1363 raw r||s(64 字节)。
    if (strlen($raw) !== 64) {
        return null;
    }
    $parts = [];
    foreach ([substr($raw, 0, 32), substr($raw, 32, 32)] as $part) {
        $integer = ltrim($part, "\0");
        if ($integer === '') {
            $integer = "\0";
        }
        if ((ord($integer[0]) & 0x80) !== 0) {
            $integer = "\0" . $integer;
        }
        // 单个 P-256 整数 DER 长度最多 33 字节,使用短格式即可。
        $parts[] = "\x02" . chr(strlen($integer)) . $integer;
    }
    $body = $parts[0] . $parts[1];
    return "\x30" . chr(strlen($body)) . $body;
}

function licensePublicKeyPem(): string
{
    $raw = licenseBase64UrlDecode(LICENSE_PUBLIC_KEY_B64U);
    if ($raw === null || strlen($raw) !== 65 || $raw[0] !== "\x04") {
        fail(500, 'Pro 授权公钥配置无效');
    }
    // SubjectPublicKeyInfo: id-ecPublicKey + prime256v1 + 65 字节未压缩点。
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function licenseVerifyKey(string $key): array
{
    $key = trim($key);
    if ($key === '' || strlen($key) > 16384 || !str_starts_with($key, 'UMPRO1.')) {
        fail(403, 'Pro License 缺失或格式无效');
    }
    $parts = explode('.', substr($key, 7));
    if (count($parts) !== 2) {
        fail(403, 'Pro License 格式无效');
    }
    [$payloadB64, $signatureB64] = $parts;
    $payload = licenseBase64UrlDecode($payloadB64);
    $signature = licenseBase64UrlDecode($signatureB64);
    $info = $payload === null ? null : json_decode($payload, true);
    if (!is_array($info) || !is_string($info['email'] ?? null) || ($info['tier'] ?? null) !== 'pro'
        || ($info['autoBind'] ?? null) !== true || !array_key_exists('guid', $info) || $info['guid'] !== null
        || !array_key_exists('exp', $info) || ($info['exp'] !== null && !is_numeric($info['exp']))) {
        fail(403, 'Pro License 无效或旧格式已停用');
    }
    $der = $signature === null ? null : licenseRawSignatureToDer($signature);
    if ($der === null || !function_exists('openssl_verify')
        || openssl_verify($payloadB64, $der, licensePublicKeyPem(), OPENSSL_ALGO_SHA256) !== 1) {
        fail(403, 'Pro License 签名无效');
    }
    if ($info['exp'] !== null && time() > (int) $info['exp']) {
        fail(403, 'Pro License 已过期');
    }
    return $info;
}

function licenseServerGuid(): string
{
    $vars = @parse_ini_file(ARRAY_VAR_FILE, false, INI_SCANNER_RAW);
    // Unraid 7.4 将 flashGuid 改为 flashGUID;按字段名小写兼容新旧系统,绑定哈希口径不变。
    $normalized = is_array($vars) ? array_change_key_case($vars, CASE_LOWER) : [];
    $guid = trim((string) ($normalized['flashguid'] ?? ''));
    if ($guid === '') {
        fail(403, '无法读取本机 unRAID 标识,暂不能确认 Pro 授权');
    }
    return $guid;
}

function licenseReceiptMatches(array $record): bool
{
    $cached = readJsonFile(LICENSE_RECEIPT_FILE);
    foreach (['keyHash', 'guidHash'] as $field) {
        if (!is_string($cached[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/', $cached[$field])) {
            return false;
        }
        if (!hash_equals($cached[$field], $record[$field])) {
            return false;
        }
    }
    return true;
}

function licenseCenterBind(array $record): string
{
    if (!function_exists('curl_init')) {
        return 'unavailable';
    }
    $curl = curl_init(LICENSE_BIND_URL);
    if ($curl === false) {
        return 'unavailable';
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($record, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($status === 409) {
        return 'mismatch';
    }
    if (!is_string($response) || $status !== 200) {
        return 'unavailable';
    }
    $body = json_decode($response, true);
    $result = is_array($body) ? ($body['status'] ?? '') : '';
    return in_array($result, ['bound', 'active'], true) ? 'bound' : 'unavailable';
}

function licenseWriteReceipt(array $record): void
{
    $data = json_encode([
        'version' => 1,
        'keyHash' => $record['keyHash'],
        'guidHash' => $record['guidHash'],
        'boundAt' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
    $tmp = LICENSE_RECEIPT_FILE . '.' . getmypid() . '.tmp';
    if (!is_string($data) || @file_put_contents($tmp, $data, LOCK_EX) === false) {
        fail(403, '无法保存本机 Pro 授权凭证,请检查后端安装与存储权限');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, LICENSE_RECEIPT_FILE)) {
        @unlink($tmp);
        fail(403, '无法保存本机 Pro 授权凭证,请检查后端安装与存储权限');
    }
}

function requireProLicense(): void
{
    $key = $_SERVER['HTTP_X_UNRAID_MOBILE_LICENSE'] ?? '';
    if (!is_string($key) || trim($key) === '') {
        fail(403, '该功能需要 Pro License,请先在设置中激活');
    }
    licenseVerifyKey($key);
    $record = [
        'keyHash' => hash('sha256', trim($key)),
        'guidHash' => hash('sha256', licenseServerGuid()),
    ];
    if (licenseReceiptMatches($record)) {
        return; // 本机已完成首次授权,后续完全离线,不访问中心。
    }
    $result = licenseCenterBind($record);
    if ($result === 'mismatch') {
        fail(403, '本 License 已绑定其他 unRAID 服务器');
    }
    if ($result !== 'bound') {
        // 403 不会被 dockerupdate 的旧 GraphQL 兼容降级误认为"后端不可用"。
        fail(403, '本机后端首次确认 Pro 授权需要联网,请联网后重试');
    }
    licenseWriteReceipt($record);
}

function requireProAction(string $action, string $method): void
{
    $protected = $method === 'GET' ? PRO_GET_ACTIONS : PRO_PUT_ACTIONS;
    if (in_array($action, $protected, true)) {
        requireProLicense();
    }
}

function tailFile(string $path): string
{
    $size = @filesize($path);
    if ($size === false) {
        return '';
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return '';
    }
    if ($size > LOG_TAIL_BYTES) {
        fseek($fh, -LOG_TAIL_BYTES, SEEK_END);
    }
    $content = (string) stream_get_contents($fh);
    fclose($fh);
    return $content;
}

function readAutostart(string $dir): bool
{
    return trim((string) @file_get_contents($dir . '/autostart')) === 'true';
}

/**
 * 【续 51 2026-07-19】读 CPU 温度:直读 /sys/class/hwmon 的 CPU 传感器 hwmon。
 * 纯内核 sysfs 文件读取,不执行任何外部命令、不触碰块设备,全盘 standby 下不会唤盘
 * (续 46.5 实锤 GraphQL metrics.temperature 会触发 unraid-api 跑 smartctl 全扫唤盘,
 *  故前端温度改走这里;该红线依旧有效,勿恢复 GraphQL temperature 查询)。
 *
 * 返回 ['celsius' => float|null, 'sensor' => string|null];无 CPU 传感器时两者皆 null。
 */
function readCpuTemp(): array
{
    // CPU 传感器白名单:coretemp(Intel)/k10temp+zenpower(AMD)/cpu_thermal(ARM)。
    // 排除 nvme(盘温,且读盘温接口有唤盘风险)、acpitz(主板)、it87 等 Super-IO(含义不确定)。
    $cpuSensorNames = ['coretemp', 'k10temp', 'zenpower', 'cpu_thermal'];
    // 优先的 label:整包温度
    $preferredLabels = ['package id 0', 'tctl', 'tdie'];

    foreach (glob('/sys/class/hwmon/hwmon*') ?: [] as $hwmon) {
        $name = trim((string) @file_get_contents($hwmon . '/name'));
        if (!in_array($name, $cpuSensorNames, true)) {
            continue;
        }
        $temps = []; // label(小写) => 毫度
        foreach (glob($hwmon . '/temp*_input') ?: [] as $input) {
            $raw = trim((string) @file_get_contents($input));
            if (!is_numeric($raw)) {
                continue;
            }
            $labelFile = preg_replace('/_input$/', '_label', $input);
            $label = strtolower(trim((string) @file_get_contents((string) $labelFile)));
            $temps[$label !== '' ? $label : basename($input)] = (int) $raw;
        }
        if ($temps === []) {
            continue;
        }
        foreach ($preferredLabels as $want) {
            if (isset($temps[$want])) {
                return ['celsius' => round($temps[$want] / 1000, 1), 'sensor' => $name . '/' . $want];
            }
        }
        // 无整包 label(如部分 k10temp 只有单值):取最大值
        $max = max($temps);
        return ['celsius' => round($max / 1000, 1), 'sensor' => $name . '/max'];
    }
    return ['celsius' => null, 'sensor' => null];
}

function readCgroupMemory(string $group): ?int
{
    $current = trim((string) @file_get_contents($group . '/memory.current'));
    if (!is_numeric($current)) {
        return null;
    }
    $inactiveFile = 0;
    foreach (@file($group . '/memory.stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^inactive_file\s+(\d+)$/', $line, $matches)) {
            $inactiveFile = (int) $matches[1];
            break;
        }
    }
    return max(0, (int) $current - $inactiveFile);
}

function readMemoryBreakdown(): ?array
{
    $values = [];
    foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^(\w+):\s+(\d+)\s+kB$/', $line, $matches)) {
            $values[$matches[1]] = (int) $matches[2] * 1024;
        }
    }
    $total = $values['MemTotal'] ?? null;
    $available = $values['MemAvailable'] ?? null;
    if ($total === null || $available === null) {
        return null;
    }
    $docker = readCgroupMemory('/sys/fs/cgroup/docker');
    $vm = readCgroupMemory('/sys/fs/cgroup/machine');
    $used = max(0, $total - $available);
    $system = max(0, $used - ($docker ?? 0) - ($vm ?? 0));
    $cache = ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0) + ($values['SReclaimable'] ?? 0);
    return [
        'totalBytes' => $total,
        'availableBytes' => $available,
        'systemBytes' => $system,
        'dockerBytes' => $docker,
        'vmBytes' => $vm,
        'cacheBytes' => $cache,
    ];
}

function readCpuPowerCounter(): ?array
{
    foreach (glob('/sys/class/powercap/intel-rapl:*/name') ?: [] as $nameFile) {
        if (!str_starts_with(trim((string) @file_get_contents($nameFile)), 'package-')) {
            continue;
        }
        $zone = dirname($nameFile);
        $energy = trim((string) @file_get_contents($zone . '/energy_uj'));
        $range = trim((string) @file_get_contents($zone . '/max_energy_range_uj'));
        if (!is_numeric($energy) || !is_numeric($range)) {
            continue;
        }
        return [
            'energyUj' => $energy,
            'maxEnergyRangeUj' => $range,
            'sensor' => basename($zone),
        ];
    }
    return null;
}

function intelGpuName(): string
{
    // lspci 结果静态,进程内缓存,避免每次 GPU 采样都 fork 一次 lspci
    static $cachedName = null;
    if ($cachedName !== null) {
        return $cachedName;
    }
    $lines = preg_split('/\r?\n/', (string) @shell_exec(PATH_ENV . ' lspci -D -nn 2>/dev/null')) ?: [];
    foreach ($lines as $line) {
        if (stripos($line, 'Intel') === false
            || !preg_match('/(VGA compatible controller|3D controller|Display controller)(?:\s*\[[0-9a-f]+\])?:\s*(.+)$/i', $line, $matches)) {
            continue;
        }
        return $cachedName = trim($matches[2]);
    }
    return $cachedName = 'Intel GPU';
}

function readIntelGpus(): array
{
    if (!is_executable('/usr/bin/intel_gpu_top')) {
        return [];
    }
    $output = (string) @shell_exec(
        PATH_ENV . ' timeout 4 /usr/bin/intel_gpu_top -J -n 2 -s 500 2>/dev/null'
    );
    // 部分 intel_gpu_top 版本的多样本 JSON 在相邻 period 对象间漏逗号
    $output = preg_replace('/\}\s*\n(\{\s*"period")/', "},\n$1", $output) ?? $output;
    $samples = json_decode($output, true);
    if (!is_array($samples) || $samples === []) {
        return [];
    }
    $sample = end($samples);
    if (!is_array($sample)) {
        return [];
    }
    $engines = [];
    $utilization = 0.0;
    foreach (($sample['engines'] ?? []) as $name => $engine) {
        if (!is_array($engine) || !is_numeric($engine['busy'] ?? null)) {
            continue;
        }
        $busy = max(0.0, min(100.0, (float) $engine['busy']));
        $engines[] = ['name' => (string) $name, 'percent' => $busy];
        $utilization = max($utilization, $busy);
    }
    return [[
        'id' => 'intel-0',
        'name' => intelGpuName(),
        'driver' => 'intel',
        'utilizationPercent' => $utilization,
        'memoryUtilizationPercent' => null,
        'memoryUsedBytes' => null,
        'memoryTotalBytes' => null,
        'powerWatts' => is_numeric($sample['power']['GPU'] ?? null) ? (float) $sample['power']['GPU'] : null,
        'frequencyMHz' => is_numeric($sample['frequency']['actual'] ?? null) ? (float) $sample['frequency']['actual'] : null,
        'temperatureCelsius' => null,
        'engines' => $engines,
    ]];
}

function readNvidiaGpus(): array
{
    if (!is_executable('/usr/bin/nvidia-smi')) {
        return [];
    }
    $fields = 'name,utilization.gpu,utilization.memory,memory.used,memory.total,power.draw,clocks.sm,temperature.gpu';
    $output = (string) @shell_exec(
        PATH_ENV . ' timeout 3 /usr/bin/nvidia-smi --query-gpu=' . $fields . ' --format=csv,noheader,nounits 2>/dev/null'
    );
    $gpus = [];
    foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        $values = array_map('trim', str_getcsv($line));
        if (count($values) !== 8) {
            continue;
        }
        $number = static fn(string $value): ?float => is_numeric($value) ? (float) $value : null;
        $memoryUsed = $number($values[3]);
        $memoryTotal = $number($values[4]);
        $gpus[] = [
            'id' => 'nvidia-' . $index,
            'name' => $values[0],
            'driver' => 'nvidia',
            'utilizationPercent' => $number($values[1]),
            'memoryUtilizationPercent' => $number($values[2]),
            'memoryUsedBytes' => $memoryUsed === null ? null : (int) round($memoryUsed * 1048576),
            'memoryTotalBytes' => $memoryTotal === null ? null : (int) round($memoryTotal * 1048576),
            'powerWatts' => $number($values[5]),
            'frequencyMHz' => $number($values[6]),
            'temperatureCelsius' => $number($values[7]),
            'engines' => [],
        ];
    }
    return $gpus;
}

function readSystemMetrics(): array
{
    return [
        'cpu' => readCpuPowerCounter(),
        'memory' => readMemoryBreakdown(),
        'gpus' => readGpusCached(),
    ];
}

/**
 * GPU 采样结果按 TTL 缓存(tmp 文件)。intel_gpu_top(-n 2 -s 500)自身采样约 1s、
 * nvidia-smi 每次冷启动进程;若随 1s liveTick 每次都 fork,服务器端开销大且会把
 * 串行 PollLoop 的实际周期拖到 2s+。这里 GPU 按 4s 降频(CPU 功耗/内存仍每次实时读),
 * 对趋势观察足够,同时省去约 3/4 的外部命令;原生与 Web 前端均无需改动。
 */
function readGpusCached(int $ttl = 4): array
{
    $cacheFile = '/tmp/unraid-mobile-compose-gpus.json';
    $now = time();
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false) {
        $decoded = json_decode($cached, true);
        if (is_array($decoded) && isset($decoded['ts'], $decoded['gpus'])
            && is_array($decoded['gpus']) && ($now - (int) $decoded['ts']) < $ttl) {
            return $decoded['gpus'];
        }
    }
    $gpus = array_merge(readIntelGpus(), readNvidiaGpus());
    @file_put_contents($cacheFile, json_encode(['ts' => $now, 'gpus' => $gpus]), LOCK_EX);
    return $gpus;
}

/**
 * 网卡状态与累积字节:直读 /proc/net/dev 和 /sys/class/net,不执行外部命令。
 * 响应结构与 GraphQL networkInfo 一致,计数用字符串避免 64 位整数精度损失。
 */
function readNetworkInfo(): array
{
    $lines = @file('/proc/net/dev', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        fail(500, '无法读取网卡计数');
    }

    $interfaces = [];
    $metrics = [];
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$left, $right] = explode(':', $line, 2);
        $name = trim($left);
        $fields = preg_split('/\s+/', trim($right));
        if ($name === '' || count($fields) < 9 || !is_numeric($fields[0]) || !is_numeric($fields[8])) {
            continue;
        }
        $status = trim((string) @file_get_contents('/sys/class/net/' . $name . '/operstate'));
        $interfaces[] = ['name' => $name, 'status' => $status !== '' ? $status : 'Unknown'];
        $metrics[] = ['name' => $name, 'bytesReceived' => $fields[0], 'bytesSent' => $fields[8]];
    }

    return [
        'info' => ['networkInterfaces' => $interfaces],
        'metrics' => ['network' => $metrics],
    ];
}

/**
 * 【续 101 2026-08-10】VM 详情增强:读 libvirt XML 返回 vcpus/内存/磁盘/网卡/
 * 图形/直通/快照(GraphQL VmDomain 只有 id/name/state,做不了详情)。
 * 只读操作:virsh dumpxml/dominfo/snapshot-list + qemu-img info,无任何写。
 * 安全:vm 名走 validName 同款白名单正则(不含项目目录存在性检查——VM 与
 * compose 项目无关);所有 shell 参数 escapeshellarg。
 */
function readVmInfo(string $vm): array
{
    $arg = escapeshellarg($vm);
    $xml = (string) @shell_exec(PATH_ENV . ' virsh dumpxml ' . $arg . ' 2>/dev/null');
    if (trim($xml) === '' && !isVmUuid($vm)) {
        $xml = (string) @file_get_contents('/etc/libvirt/qemu/' . $vm . '.xml');
    }
    if (trim($xml) === '') {
        fail(404, '无法读取 VM XML(virsh 不可用且无配置文件): ' . $vm);
    }
    $dom = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    if ($dom === false) {
        fail(500, 'VM XML 解析失败: ' . $vm);
    }

    $attr = static fn($node, string $name): ?string =>
        isset($node[$name]) ? (string) $node[$name] : null;

    $vcpus = null;
    $vcpuMax = null;
    if (isset($dom->vcpu)) {
        $current = $attr($dom->vcpu, 'current');
        $vcpuMax = (int) (string) $dom->vcpu;
        $vcpus = (int) ($current !== null ? $current : $vcpuMax);
    }

    $memory = null;
    if (isset($dom->memory)) {
        $memory = [
            'max' => (int) (string) $dom->memory,
            'current' => isset($dom->currentMemory) ? (int) (string) $dom->currentMemory : (int) (string) $dom->memory,
            'unit' => $attr($dom->memory, 'unit') ?? 'KiB',
        ];
    }

    $autostart = null;
    $dominfo = (string) @shell_exec(PATH_ENV . ' virsh dominfo ' . $arg . ' 2>/dev/null');
    if (preg_match('/^Autostart:\s*(\w+)/m', $dominfo, $m)) {
        $autostart = strtolower($m[1]) === 'enable';
    }

    $disks = [];
    foreach ($dom->devices->disk ?? [] as $disk) {
        if (($attr($disk, 'device') ?? 'disk') !== 'disk') {
            continue;
        }
        $type = $attr($disk, 'type');
        $path = null;
        if (isset($disk->source)) {
            $path = $attr($disk->source, 'file') ?? $attr($disk->source, 'dev');
        }
        $entry = [
            'type' => $type,
            'path' => $path,
            'bus' => isset($disk->target) ? $attr($disk->target, 'bus') : null,
            'dev' => isset($disk->target) ? $attr($disk->target, 'dev') : null,
            'format' => isset($disk->driver) ? $attr($disk->driver, 'type') : null,
            'size' => null,
            'actualSize' => null,
        ];
        if ($path !== null && $type === 'file') {
            $imgOut = (string) @shell_exec(
                PATH_ENV . ' timeout 5 qemu-img info --output json ' . escapeshellarg($path) . ' 2>/dev/null'
            );
            $img = json_decode($imgOut, true);
            if (is_array($img)) {
                $entry['size'] = isset($img['virtual-size']) ? (int) $img['virtual-size'] : null;
                $entry['actualSize'] = isset($img['actual-size']) ? (int) $img['actual-size'] : null;
            }
        }
        $disks[] = $entry;
    }

    $interfaces = [];
    foreach ($dom->devices->interface ?? [] as $if) {
        $interfaces[] = [
            'type' => $attr($if, 'type'),
            'bridge' => isset($if->source)
                ? ($attr($if->source, 'bridge') ?? $attr($if->source, 'network'))
                : null,
            'mac' => isset($if->mac) ? $attr($if->mac, 'address') : null,
            'model' => isset($if->model) ? $attr($if->model, 'type') : null,
        ];
    }

    $graphics = null;
    if (isset($dom->devices->graphics)) {
        $g = $dom->devices->graphics;
        $graphics = [
            'type' => $attr($g, 'type'),
            'port' => $attr($g, 'port'),
            'autoport' => ($attr($g, 'autoport') ?? '') === 'yes',
            'listen' => isset($g->listen)
                ? ($attr($g->listen, 'address') ?? $attr($g, 'listen'))
                : $attr($g, 'listen'),
        ];
    }

    $hostDevices = [];
    foreach ($dom->devices->hostdev ?? [] as $hd) {
        $hdType = $attr($hd, 'type');
        if ($hdType === 'pci' && isset($hd->source->address)) {
            $hostDevices[] = [
                'type' => 'pci',
                'domain' => $attr($hd->source->address, 'domain'),
                'bus' => $attr($hd->source->address, 'bus'),
                'slot' => $attr($hd->source->address, 'slot'),
                'function' => $attr($hd->source->address, 'function'),
            ];
        } elseif ($hdType === 'usb' && isset($hd->source)) {
            $hostDevices[] = [
                'type' => 'usb',
                'vendorId' => isset($hd->source->vendor) ? $attr($hd->source->vendor, 'id') : null,
                'productId' => isset($hd->source->product) ? $attr($hd->source->product, 'id') : null,
            ];
        }
    }

    $snapshots = [];
    $snapOut = (string) @shell_exec(PATH_ENV . ' virsh snapshot-list ' . $arg . ' --name 2>/dev/null');
    foreach (preg_split('/\r?\n/', trim($snapOut)) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $snapshots[] = $line;
        }
    }

    $templateOs = null;
    if (isset($dom->metadata)) {
        $template = $dom->metadata->children('http://unraid')->vmtemplate;
        if (isset($template->attributes()->os)) {
            $templateOs = (string) $template->attributes()->os;
        }
    }
    $firmware = isset($dom->os) ? $attr($dom->os, 'firmware') : null;
    if ($firmware === null && isset($dom->os->loader)) {
        $firmware = (string) $dom->os->loader;
    }

    return [
        'name' => isset($dom->name) ? (string) $dom->name : $vm,
        'uuid' => isset($dom->uuid) ? (string) $dom->uuid : null,
        'vcpus' => $vcpus,
        'vcpuMax' => $vcpuMax,
        'memory' => $memory,
        'autostart' => $autostart,
        'firmware' => $firmware,
        'machine' => isset($dom->os->type) ? $attr($dom->os->type, 'machine') : null,
        'arch' => isset($dom->os->type) ? $attr($dom->os->type, 'arch') : null,
        'os' => $templateOs,
        'disks' => $disks,
        'interfaces' => $interfaces,
        'graphics' => $graphics,
        'hostDevices' => $hostDevices,
        'snapshots' => $snapshots,
    ];
}

function isVmUuid(string $vm): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $vm) === 1;
}

function readVmStats(string $vm): array
{
    $out = (string) @shell_exec(
        PATH_ENV . ' virsh domstats --raw --cpu-total --balloon --block --interface ' . escapeshellarg($vm) . ' 2>/dev/null'
    );
    if (trim($out) === '') {
        fail(409, '无法读取 VM 运行指标,请确认虚拟机正在运行');
    }
    $stats = [];
    foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
        if (preg_match('/^\s*([A-Za-z0-9_.]+)=(.*)$/', $line, $m)) {
            $stats[$m[1]] = $m[2];
        }
    }
    if (!isset($stats['cpu.time']) || !is_numeric($stats['cpu.time'])) {
        fail(500, 'VM 指标缺少 cpu.time');
    }
    $optionalInt = static fn(string $key): ?int =>
        isset($stats[$key]) && is_numeric($stats[$key]) ? (int) $stats[$key] : null;
    $memory = [
        'current' => $optionalInt('balloon.current'),
        'max' => $optionalInt('balloon.maximum'),
        'unit' => 'KiB',
        'unused' => $optionalInt('balloon.unused'),
        'available' => $optionalInt('balloon.available'),
        'usable' => $optionalInt('balloon.usable'),
        'rss' => $optionalInt('balloon.rss'),
    ];
    $networks = [];
    $netCount = max(0, (int) ($stats['net.count'] ?? 0));
    for ($i = 0; $i < $netCount; $i++) {
        $networks[] = [
            'name' => $stats["net.$i.name"] ?? "net$i",
            'rxBytes' => $stats["net.$i.rx.bytes"] ?? '0',
            'txBytes' => $stats["net.$i.tx.bytes"] ?? '0',
        ];
    }
    $disks = [];
    $blockCount = max(0, (int) ($stats['block.count'] ?? 0));
    for ($i = 0; $i < $blockCount; $i++) {
        $disks[] = [
            'name' => $stats["block.$i.name"] ?? "disk$i",
            'path' => $stats["block.$i.path"] ?? null,
            'readBytes' => $stats["block.$i.rd.bytes"] ?? '0',
            'writeBytes' => $stats["block.$i.wr.bytes"] ?? '0',
        ];
    }
    return [
        'sampledAt' => (int) floor(microtime(true) * 1000),
        'cpuTimeNs' => $stats['cpu.time'],
        'memory' => $memory,
        'networks' => $networks,
        'disks' => $disks,
    ];
}

function readVmLog(string $vm, int $offset): array
{
    $name = trim((string) @shell_exec(PATH_ENV . ' virsh domname ' . escapeshellarg($vm) . ' 2>/dev/null'));
    if ($name === '' || basename($name) !== $name || str_contains($name, "\0")) {
        fail(404, '找不到 VM 或 QEMU 日志名称');
    }
    $path = '/var/log/libvirt/qemu/' . $name . '.log';
    if (!is_file($path)) {
        fail(404, 'QEMU/libvirt 日志不存在');
    }
    clearstatcache(true, $path);
    $size = filesize($path);
    if ($size === false) {
        fail(500, '无法读取 QEMU/libvirt 日志大小');
    }
    $maxBytes = 262144;
    $reset = $offset < 0 || $offset > $size;
    $start = $reset ? max(0, $size - $maxBytes) : $offset;
    $length = min($maxBytes, $size - $start);
    $content = '';
    if ($length > 0) {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            fail(500, '无法打开 QEMU/libvirt 日志');
        }
        try {
            if (fseek($handle, $start) !== 0) {
                fail(500, '无法定位 QEMU/libvirt 日志');
            }
            $content = fread($handle, $length);
            if ($content === false) {
                fail(500, '无法读取 QEMU/libvirt 日志');
            }
        } finally {
            fclose($handle);
        }
    }
    $nextOffset = $start + strlen($content);
    if ($reset && $start > 0 && ($newline = strpos($content, "\n")) !== false) {
        $content = substr($content, $newline + 1);
    }
    return ['content' => $content, 'offset' => $nextOffset, 'size' => $size, 'reset' => $reset];
}

/**
 * 补充缓存过期时后台补扫。HTTP 请求只读现有结果,不同步等待 Registry;
 * flock 防止并发请求启动多个扫描进程,锁文件保留在 tmpfs 中。
 */
function scheduleImageUpdateScan(array $extra): void
{
    $interval = UPDATE_SCAN_INTERVAL;
    foreach ($extra as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $remote = $entry['remote'] ?? null;
        if (!is_string($remote) || $remote === '' || ($entry['status'] ?? null) === 'stale') {
            $interval = min($interval, UPDATE_SCAN_RETRY_INTERVAL);
        }
    }
    $checkedAt = @filemtime(UPDATE_EXTRA_STATUS_FILE);
    if ($checkedAt !== false && time() - $checkedAt < $interval) {
        return;
    }
    if (!is_file(__DIR__ . '/update-status.php')) {
        return;
    }
    $lock = @fopen(UPDATE_SCAN_LOCK_FILE, 'c');
    if ($lock === false) {
        return;
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        return;
    }
    @flock($lock, LOCK_UN);
    @fclose($lock);
    exec(
        PATH_ENV . ' nohup /usr/bin/php ' . escapeshellarg(__DIR__ . '/update-status.php')
        . ' --scan > /dev/null 2>&1 &'
    );
}

/**
 * 有更新的镜像 ref 列表:合并 webGui 主缓存与当前容器镜像的补充缓存。
 * 新鲜补扫的远端 digest 优先,主缓存用于补扫缺失或失败时的回退。
 * 返回时剥掉 Docker Hub 官方镜像的 library/ 前缀,与容器 image 原值对齐。
 * local digest 每次实测,避免手动 pull / Watchtower 后沿用冻结的旧值;
 * 远端 digest 由每日 webGui 任务和后台补扫维护(成功 24h、失败 5min 重试)。
 * 本地或远端 digest 缺失时,回退缓存 local/status;unknown 不算有更新。
 */
function readImageUpdates(): array
{
    $base = readJsonFile(UPDATE_STATUS_FILE) ?? [];
    $extra = readJsonFile(UPDATE_EXTRA_STATUS_FILE) ?? [];
    $data = $base;
    foreach ($extra as $image => $entry) {
        if (!is_string($image) || !is_array($entry)) {
            continue;
        }
        $remote = $entry['remote'] ?? null;
        $freshRemote = is_string($remote) && $remote !== '' && ($entry['status'] ?? null) !== 'stale';
        if (!array_key_exists($image, $data) || $freshRemote) {
            $data[$image] = $entry;
        }
    }
    scheduleImageUpdateScan($extra);
    if ($data === []) {
        return [];
    }

    // 本地真实 digest:逐候选 `docker image inspect` 取 RepoDigests[0]——
    // 与 webGui inspectLocalVersion 同语义(tag 当前解析值)。
    // 【续 112.3】不能用 docker images 的行输出:同一 image ID 多 RepoDigest 时
    // (tag 被推重过同内容新 digest)会同 repo:tag 吐多行,取错行 → 假阳性
    // (postgres:17-alpine 实证:tag 解析值 18cfe3e==remote 已最新,
    //  旧 digest d4bb0a8 行干扰)。逐 ref inspect,单个失败只跳过该项。
    $actualLocalOf = static function (string $image): ?string {
        $out = [];
        @exec(
            PATH_ENV . ' ' . DOCKER . ' image inspect ' . escapeshellarg($image)
            . " --format '{{index .RepoDigests 0}}' 2>/dev/null",
            $out
        );
        $repoDigest = trim((string) ($out[0] ?? ''));
        $at = strpos($repoDigest, '@');
        if ($at === false) {
            return null;
        }
        $digest = substr($repoDigest, $at + 1);
        return $digest !== '' ? $digest : null;
    };

    $updates = [];
    foreach ($data as $image => $entry) {
        if (!is_string($image) || !is_array($entry)) {
            continue;
        }
        $remote = $entry['remote'] ?? null;
        $remote = is_string($remote) && $remote !== '' ? $remote : null;
        // inspect 用缓存原 key(docker 兼容 library/ 前缀);返回列表用剥前缀的 ref
        $ref = (string) preg_replace('#^library/#', '', $image);
        // 有 remote 才值得实测(无 remote 的 inspect 了也没东西可比)
        $actualLocal = $remote !== null ? $actualLocalOf($image) : null;

        if ($actualLocal !== null && $remote !== null) {
            $hasUpdate = $actualLocal !== $remote; // 实测 local vs 缓存 remote
        } else {
            $local = $entry['local'] ?? null;
            if (is_string($local) && $local !== '' && $remote !== null) {
                $hasUpdate = $local !== $remote; // 回退:缓存原值比对
            } else {
                $hasUpdate = ($entry['status'] ?? null) === 'false';
            }
        }
        if ($hasUpdate) {
            $updates[] = $ref;
        }
    }
    sort($updates);
    return $updates;
}

/** 图标 URL 与前端同口径:只接受无凭据 HTTPS 绝对地址,其余一律无图标。 */
function containerIconUrl(mixed $raw): ?string
{
    if (!is_string($raw)) {
        return null;
    }
    $url = trim($raw);
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20]/', $url)) {
        return null;
    }
    $parts = @parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
        return null;
    }
    return isset($parts['user']) || isset($parts['pass']) ? null : $url;
}

/** 同键多来源冲突时丢弃候选,不任意选择一个可能错误的应用图标。 */
function addContainerIconCandidate(array &$map, string $key, ?string $icon): void
{
    if ($key === '' || $icon === null) {
        return;
    }
    if (array_key_exists($key, $map) && $map[$key] !== $icon) {
        $map[$key] = null;
        return;
    }
    $map[$key] = $icon;
}

const DASHBOARD_ICONS_TREE_URL = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons@main/tree.json';
const DASHBOARD_ICONS_PNG_BASE = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/';
const DASHBOARD_ICONS_CACHE_FILE = PLUGIN_WORK_DIR . '/dashboard-icons.json';
const DASHBOARD_ICONS_CACHE_TTL = 604800;
const DASHBOARD_ICONS_RETRY_INTERVAL = 300;
const DASHBOARD_ICONS_MAX_BYTES = 4194304;

/** Dashboard Icons 官方 tree.json 的 png 文件名 → 精确 slug 集合。 */
function dashboardIconSetFromFiles(mixed $files): array
{
    $icons = [];
    if (!is_array($files)) {
        return $icons;
    }
    foreach ($files as $file) {
        if (is_string($file) && preg_match('/^([a-z0-9][a-z0-9._-]{0,127})\.png$/D', $file, $match)) {
            $icons[$match[1]] = true;
        }
    }
    return $icons;
}

function dashboardIconWriteCache(int $cachedAt, int $checkedAt, array $icons): bool
{
    $dir = dirname(DASHBOARD_ICONS_CACHE_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode([
        'cachedAt' => $cachedAt,
        'checkedAt' => $checkedAt,
        'files' => array_map(static fn(string $name): string => $name . '.png', array_keys($icons)),
    ], JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = DASHBOARD_ICONS_CACHE_FILE . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, DASHBOARD_ICONS_CACHE_FILE)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** 小索引允许短超时刷新;CA 大 feed 仍只读已有缓存。失败用旧索引并限流重试。 */
function dashboardIconSet(): array
{
    $cache = readJsonFile(DASHBOARD_ICONS_CACHE_FILE) ?? [];
    $cachedAt = is_int($cache['cachedAt'] ?? null) ? $cache['cachedAt'] : 0;
    $checkedAt = is_int($cache['checkedAt'] ?? null) ? $cache['checkedAt'] : 0;
    $icons = dashboardIconSetFromFiles($cache['files'] ?? null);
    $now = time();
    if (($icons !== [] && $now - $cachedAt < DASHBOARD_ICONS_CACHE_TTL)
        || ($checkedAt > 0 && $now - $checkedAt < DASHBOARD_ICONS_RETRY_INTERVAL)) {
        return $icons;
    }

    $http = caAppsHttpGet(DASHBOARD_ICONS_TREE_URL, 3, 5, DASHBOARD_ICONS_MAX_BYTES);
    $data = $http['ok'] ? json_decode($http['body'], true) : null;
    $fresh = is_array($data) ? dashboardIconSetFromFiles($data['png'] ?? null) : [];
    if ($fresh !== []) {
        dashboardIconWriteCache($now, $now, $fresh);
        return $fresh;
    }
    dashboardIconWriteCache($cachedAt, $now, $icons);
    return $icons;
}

function containerIconSlug(string $raw): string
{
    $slug = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($raw)) ?? '');
    return trim($slug, '.-_');
}

function containerIconFileId(mixed $id): ?string
{
    if (!is_string($id) || !preg_match('/^([a-f0-9]{64})\.(png|jpg|webp)$/D', $id, $matches)) {
        return null;
    }
    // 扩展名本身也是内容类型白名单的一部分,不能由请求指定 MIME。
    return $matches[1] . '.' . $matches[2];
}

/** 最终图标引用:公网 HTTPS 或本 API 的严格本地上传路径;绝不接受其他相对地址。 */
function containerIconReference(mixed $raw): ?string
{
    $https = containerIconUrl($raw);
    if ($https !== null) {
        return $https;
    }
    if (!is_string($raw)) {
        return null;
    }
    $value = trim($raw);
    if (!preg_match(
        '~^/compose-api/\?action=iconfile&id=([a-f0-9]{64}\.(?:png|jpg|webp))$~D',
        $value,
        $matches
    )) {
        return null;
    }
    return containerIconFileId($matches[1]) !== null ? $value : null;
}

/** 手工配置 → 可直接返回给 Web/App 的引用;损坏或文件缺失时回退自动匹配。 */
function manualContainerIcons(bool $fresh = false): array
{
    static $cache = null;
    if ($fresh) {
        $cache = null;
    }
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $config = readJsonFile(CONTAINER_ICON_CONFIG);
    foreach ($config['icons'] ?? [] as $key => $entry) {
        if (!is_string($key) || !preg_match('/^(docker|compose):[^\x00-\x1F]{1,128}$/D', $key)
            || !is_array($entry)) {
            continue;
        }
        $type = $entry['type'] ?? null;
        if ($type === 'url') {
            $icon = containerIconUrl($entry['value'] ?? null);
        } elseif ($type === 'file') {
            $id = containerIconFileId($entry['id'] ?? null);
            $icon = $id !== null && is_file(CONTAINER_ICON_FILES_DIR . '/' . $id)
                ? '/compose-api/?action=iconfile&id=' . $id
                : null;
        } else {
            $icon = null;
        }
        if ($icon !== null) {
            $cache[$key] = $icon;
        }
    }
    return $cache;
}

function writeManualContainerIcon(string $key, ?array $entry): void
{
    $parent = dirname(CONTAINER_ICON_CONFIG);
    if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
        fail(500, '图标配置目录创建失败');
    }
    $lock = @fopen(CONTAINER_ICON_CONFIG . '.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            @fclose($lock);
        }
        fail(503, '图标配置正被其他请求更新,请重试');
    }
    try {
        $config = readJsonFile(CONTAINER_ICON_CONFIG) ?? ['version' => 1, 'icons' => []];
        $icons = is_array($config['icons'] ?? null) ? $config['icons'] : [];
        if ($entry === null) {
            unset($icons[$key]);
        } else {
            $icons[$key] = $entry;
        }
        $json = json_encode(['version' => 1, 'icons' => $icons],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            fail(500, '图标配置编码失败');
        }
        $tmp = CONTAINER_ICON_CONFIG . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            fail(500, '图标配置写入失败');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, CONTAINER_ICON_CONFIG)) {
            @unlink($tmp);
            fail(500, '图标配置保存失败');
        }
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
    manualContainerIcons(true);
}

/** 仅 magic bytes 白名单;不解码/重编码图片,避免图像炸弹消耗宿主 CPU/内存。 */
function containerIconImageType(string $bytes): ?string
{
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        return 'png';
    }
    if (str_starts_with($bytes, "\xff\xd8\xff")) {
        return 'jpg';
    }
    if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
        return 'webp';
    }
    return null;
}

/** 内容寻址存储;自动导入失败时返回 null,让图标解析继续降级而非中断容器列表。 */
function storeContainerIconBytes(string $bytes): ?string
{
    $extension = containerIconImageType($bytes);
    if ($bytes === '' || strlen($bytes) > CONTAINER_ICON_MAX_BYTES || $extension === null) {
        return null;
    }
    if (!is_dir(CONTAINER_ICON_FILES_DIR)
        && !@mkdir(CONTAINER_ICON_FILES_DIR, 0700, true)
        && !is_dir(CONTAINER_ICON_FILES_DIR)) {
        return null;
    }
    $id = hash('sha256', $bytes) . '.' . $extension;
    $path = CONTAINER_ICON_FILES_DIR . '/' . $id;
    if (!is_file($path)) {
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            return null;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return null;
        }
    }
    return $id;
}

function storeContainerIconUpload(mixed $encoded): string
{
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 2796204) {
        fail(400, '图片必须是 Base64,且原始大小不能超过 2MB');
    }
    $bytes = base64_decode($encoded, true);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > CONTAINER_ICON_MAX_BYTES) {
        fail(400, '图片 Base64 无效或超过 2MB');
    }
    if (containerIconImageType($bytes) === null) {
        fail(415, '仅支持 PNG、JPEG、WebP 图片');
    }
    $id = storeContainerIconBytes($bytes);
    if ($id === null) {
        fail(500, '图片保存失败');
    }
    return $id;
}

/**
 * 用户模板的 file:// 图标仅允许 Unraid 标准 docker-icons 目录。
 * 导入为内容哈希文件后经统一鉴权接口读取,不把宿主路径返回给 Web/App。
 */
function containerTemplateIconReference(mixed $raw): ?string
{
    $https = containerIconUrl($raw);
    if ($https !== null) {
        return $https;
    }
    if (!is_string($raw)) {
        return null;
    }
    $value = trim($raw);
    if (!str_starts_with($value, 'file://')) {
        return null;
    }
    $path = substr($value, 7);
    $iconDirs = ['/mnt/cache/appdata/docker-icons', '/mnt/user/appdata/docker-icons'];
    if ($path === '' || preg_match('/[\x00-\x1F?#\\\\]/', $path)
        || !preg_match('/\.(png|jpe?g|webp)$/iD', $path)
        || !in_array(dirname($path), $iconDirs, true)
        || !is_file($path) || is_link($path)) {
        return null;
    }
    $realPath = realpath($path);
    $realDir = realpath(dirname($path));
    $size = @filesize($path);
    if ($realPath === false || $realDir === false || dirname($realPath) !== $realDir
        || !is_int($size) || $size < 1 || $size > CONTAINER_ICON_MAX_BYTES) {
        return null;
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes)) {
        return null;
    }
    $id = storeContainerIconBytes($bytes);
    return $id === null ? null : '/compose-api/?action=iconfile&id=' . $id;
}

function sendContainerIconFile(mixed $rawId): void
{
    $id = containerIconFileId($rawId);
    $path = $id === null ? null : CONTAINER_ICON_FILES_DIR . '/' . $id;
    if ($id === null || $path === null || !is_file($path)) {
        fail(404, '图标文件不存在');
    }
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($id, PATHINFO_EXTENSION)];
    $size = filesize($path);
    if ($size === false || $size > CONTAINER_ICON_MAX_BYTES) {
        fail(404, '图标文件不可用');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: private, max-age=31536000, immutable');
    readfile($path);
    exit;
}

function setContainerIcon(array $body): array
{
    $target = $body['target'] ?? null;
    if ($target !== 'docker' && $target !== 'compose') {
        fail(400, 'target 必须是 docker 或 compose');
    }
    $name = $target === 'docker' ? dockerEditName($body['name'] ?? null) : validName($body['name'] ?? null);
    if ($target === 'docker') {
        $noop = [];
        $code = 1;
        exec(PATH_ENV . ' ' . DOCKER . ' inspect ' . escapeshellarg($name) . ' >/dev/null 2>&1', $noop, $code);
        if ($code !== 0) {
            fail(404, '容器不存在: ' . $name);
        }
    }
    $hasUrl = array_key_exists('icon', $body);
    $hasUpload = array_key_exists('data', $body);
    if ($hasUrl === $hasUpload) {
        fail(400, '必须且只能提供 icon 或 data;icon:null 表示恢复自动匹配');
    }
    if ($hasUpload) {
        $entry = ['type' => 'file', 'id' => storeContainerIconUpload($body['data'])];
    } elseif ($body['icon'] === null) {
        $entry = null;
    } else {
        $url = containerIconUrl($body['icon']);
        if ($url === null) {
            fail(400, '手工图标必须是无凭据的 HTTPS 地址');
        }
        $entry = ['type' => 'url', 'value' => $url];
    }
    writeManualContainerIcon($target . ':' . $name, $entry);
    audit('seticon', $target . ':' . $name, 'ok');

    if ($target === 'docker') {
        foreach (dockerContainerIcons() ?? [] as $row) {
            if ($row['name'] === $name) {
                return ['icon' => $row['icon']];
            }
        }
        fail(503, '图标已保存,但刷新容器图标失败');
    }
    $services = [];
    $containers = projectContainersMap($services);
    $summary = stackSummary($name, composeLsMap(), $containers,
        dockerContainerIconMap(dockerContainerIcons()), $services);
    return ['icon' => $summary['icon']];
}

/**
 * 模板与 CA 只读扫描;Dashboard Icons 小索引有独立短超时/缓存,不触发 CA feed 下载。
 * 每次请求一次扫描;不逐容器读 XML 或请求远程图片。
 */
function dockerContainerIconContext(): array
{
    $names = [];
    $userRepos = [];
    $communityRepos = [];
    foreach ([TEMPLATE_USER_DIR, DOCKER_TEMPLATE_COMMUNITY_DIR] as $dir) {
        $isUser = $dir === TEMPLATE_USER_DIR;
        foreach (glob($dir . '/*.xml') ?: [] as $path) {
            $dom = pluginXml($path);
            if ($dom === null) {
                continue;
            }
            $icon = $isUser
                ? containerTemplateIconReference((string) ($dom->Icon ?? ''))
                : containerIconUrl((string) ($dom->Icon ?? ''));
            $repo = caAppsRepoKey((string) ($dom->Repository ?? ''));
            if ($isUser) {
                addContainerIconCandidate($names, trim((string) ($dom->Name ?? '')), $icon);
                addContainerIconCandidate($userRepos, $repo, $icon);
            } else {
                addContainerIconCandidate($communityRepos, $repo, $icon);
            }
        }
    }

    // CA 的 repo 是模板作者仓库,不是镜像名;只能按应用名归一化精确匹配。
    $caNames = [];
    foreach (caAppsReadCache()['records'] ?? [] as $record) {
        if (!is_array($record) || ($record['type'] ?? '') !== 'docker') {
            continue;
        }
        addContainerIconCandidate(
            $caNames,
            caAppsKey((string) ($record['name'] ?? '')),
            containerIconUrl($record['icon'] ?? null)
        );
    }
    return [
        'names' => $names,
        'userRepos' => $userRepos,
        'communityRepos' => $communityRepos,
        'caNames' => $caNames,
        'dashboard' => dashboardIconSet(),
    ];
}

/** 全部容器图标;null = Docker 查询失败(调用方保留旧值),空数组 = 当前无容器。 */
function dockerContainerIcons(): ?array
{
    $lines = [];
    $exitCode = 0;
    @exec(
        PATH_ENV . ' ' . DOCKER . ' ps -a --format ' . escapeshellarg('{{.Names}}|{{.Image}}') . ' 2>/dev/null',
        $lines,
        $exitCode
    );
    if ($exitCode !== 0) {
        return null;
    }
    $ctx = dockerContainerIconContext();
    $manual = manualContainerIcons();
    $rows = [];
    foreach ($lines as $line) {
        $parts = explode('|', trim($line), 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            continue;
        }
        [$name, $image] = $parts;
        $manualIcon = $manual['docker:' . $name] ?? null;
        $icon = $ctx['names'][$name] ?? null;
        $repo = caAppsRepoKey($image);
        foreach (['userRepos', 'communityRepos'] as $source) {
            if ($icon === null && isset($ctx[$source][$repo])) {
                $icon = $ctx[$source][$repo];
            }
        }
        $imageName = basename($repo);
        if ($icon === null) {
            foreach ([caAppsKey($imageName), caAppsKey($name)] as $key) {
                if ($icon === null && isset($ctx['caNames'][$key])) {
                    $icon = $ctx['caNames'][$key];
                }
            }
        }
        if ($icon === null) {
            foreach ([containerIconSlug($imageName), containerIconSlug($name)] as $slug) {
                if ($icon === null && $slug !== '' && isset($ctx['dashboard'][$slug])) {
                    $icon = DASHBOARD_ICONS_PNG_BASE . rawurlencode($slug) . '.png';
                }
            }
        }
        $rows[] = ['name' => $name, 'icon' => $manualIcon ?? $icon];
    }
    return $rows;
}

function dockerContainerIconMap(?array $rows): array
{
    $map = [];
    foreach ($rows ?? [] as $row) {
        if (!is_array($row) || !is_string($row['name'] ?? null) || $row['name'] === '') {
            continue;
        }
        $map[$row['name']] = containerIconReference($row['icon'] ?? null);
    }
    return $map;
}

/** Compose 栈图标:同名成员优先;多成员仅在全部解析且共享一个图标时采用。 */
function composeStackIcon(string $stackName, array $containers, array $iconMap, array $services = []): ?string
{
    $manual = manualContainerIcons()['compose:' . $stackName] ?? null;
    if ($manual !== null) {
        return $manual;
    }
    $stackKey = caAppsKey($stackName);
    $distinct = [];
    $preferred = null;
    $resolved = 0;
    $total = 0;
    foreach ($containers as $name) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        $total++;
        $icon = containerIconReference($iconMap[$name] ?? null);
        if ($icon === null) {
            continue;
        }
        $resolved++;
        $distinct[$icon] = true;
        $serviceKey = caAppsKey((string) ($services[$name] ?? ''));
        if ($preferred === null && $stackKey !== ''
            && (caAppsKey($name) === $stackKey || ($serviceKey !== '' && $serviceKey === $stackKey))) {
            $preferred = $icon;
        }
    }
    if ($preferred !== null) {
        return $preferred;
    }
    return $total > 0 && $resolved === $total && count($distinct) === 1
        ? (string) array_key_first($distinct)
        : null;
}

function stackSummary(
    string $dirName,
    array $lsMap,
    array $pcMap = [],
    array $iconMap = [],
    array $serviceMap = []
): array {
    $dir = PROJECTS_DIR . '/' . $dirName;
    $project = projectName($dirName);
    $row = $lsMap[$project] ?? null;
    $status = $row['Status'] ?? null; // 例: "running(1)" / "exited(0)"
    $containers = $pcMap[$project] ?? [];
    return [
        'name' => $dirName,
        'project' => $project,
        'status' => $status,
        'running' => is_string($status) && str_starts_with($status, 'running'),
        'autostart' => readAutostart($dir),
        'lastResult' => readJsonFile($dir . '/last_result.json'),
        'composeFile' => firstExisting($dir, composeFileCandidates()),
        // 【续 70】该栈的容器名列表(label 精确归属,自定义 container_name 也准)
        'containers' => $containers,
        'icon' => composeStackIcon($dirName, $containers, $iconMap, $serviceMap[$project] ?? []),
    ];
}

/** compose.manager 当前目录对应的规范化项目名;目录扫描失败时保守返回空列表。 */
function composeManagerProjectNames(): array
{
    $names = [];
    foreach (@scandir(PROJECTS_DIR) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !is_dir(PROJECTS_DIR . '/' . $entry)) continue;
        $project = projectName($entry);
        if ($project !== '') $names[$project] = true;
    }
    return array_keys($names);
}

/** 列表仅做文本归属判断,不 realpath 外部目录;更新前会另行规范化并复核路径。 */
function composeManagerPath(string $workingDir): bool
{
    if ($workingDir === '') return false;
    $root = realpath(PROJECTS_DIR);
    $root = rtrim(is_string($root) ? $root : PROJECTS_DIR, '/');
    $path = rtrim($workingDir, '/');
    return $path === $root || str_starts_with($path, $root . '/');
}

function externalProjectIsManaged(string $project, array $workingDirs = []): bool
{
    if (in_array($project, composeManagerProjectNames(), true)) return true;
    foreach ($workingDirs as $workingDir) {
        if (is_string($workingDir) && composeManagerPath($workingDir)) return true;
    }
    return false;
}

/** 每个 Compose project 只返回一次;manager 项目名或 manager 路径均排除。 */
function externalProjects(): array
{
    $serviceMap = [];
    $workingDirMap = [];
    $map = projectContainersMap($serviceMap, $workingDirMap, true);
    $projects = [];
    foreach ($map as $project => $containers) {
        if (!is_string($project) || $project === ''
            || externalProjectIsManaged($project, $workingDirMap[$project] ?? [])) continue;
        $containers = array_values(array_unique(array_filter($containers, 'is_string')));
        sort($containers, SORT_NATURAL | SORT_FLAG_CASE);
        $projects[] = ['project' => $project, 'containers' => $containers];
    }
    usort($projects, static fn(array $a, array $b): int => strnatcasecmp($a['project'], $b['project']));
    return $projects;
}

/**
 * 同步执行 docker compose 子命令,输出落 last_cmd.log,结果落 last_result.json
 * (与 compose.manager 插件的文件约定保持一致,插件 UI 里也能看到)
 */
function runComposeSync(string $dir, string $op, string $args): array
{
    set_time_limit(300);
    // 【续 88 2026-08-08】同步操作(up/down/restart)接入与异步同一把 .op-running 锁:
    // 此前 sync 完全不感知锁,可与进行中的 pull/rebuild 并发跑同一栈,
    // last_cmd.log/last_result.json 双写互覆。锁存在即 409,执行期间持锁,finally 兜底释放
    $lockFile = $dir . '/.op-running';
    $lock = @fopen($lockFile, 'x');
    if ($lock === false) {
        audit($op, basename($dir), 'fail');
        fail(409, '该栈有操作正在进行中,请等待完成');
    }
    fwrite($lock, $op);
    fclose($lock);
    try {
        $cmd = sprintf('cd %s && %s %s compose %s 2>&1', escapeshellarg($dir), PATH_ENV, DOCKER, $args);
        $lines = [];
        $exitCode = 1;
        exec($cmd, $lines, $exitCode);
        $output = implode("\n", $lines);
        file_put_contents($dir . '/last_cmd.log', $output);
        $result = [
            'result' => $exitCode === 0 ? 'success' : 'error',
            'exit_code' => $exitCode,
            'operation' => $op,
            'timestamp' => date('c'),
        ];
        file_put_contents($dir . '/last_result.json', json_encode($result));
        return [$exitCode, $output];
    } finally {
        @unlink($lockFile);
    }
}

/**
 * 异步执行(慢操作):nohup 后台跑,.op-running 标记文件存在 = 任务进行中,
 * 前端轮询 ?action=log 看进度。
 */
function runComposeAsync(string $dir, string $op, string $args): void
{
    // 【续 50 D3-2】锁改用 fopen 'x' 原子创建(原 file_exists+写是 TOCTOU,并发会双开 compose)
    $lockFile = $dir . '/.op-running';
    $lock = @fopen($lockFile, 'x');
    if ($lock === false) {
        audit($op, basename($dir), 'fail');
        fail(409, '该栈有操作正在进行中,请等待完成');
    }
    fwrite($lock, $op);
    fclose($lock);
    // 【续 50 D3-3】异步动作的真实成败在后台 shell 里,完成时把审计行追加进 AUDIT_LOG
    // 【续 68.2】pull/rebuild 成功后(rm .op-running 之前)回写 unraid-api 更新状态缓存,
    //   否则「更新」徽章要等 unraid-api 次日 6AM cron 才消
    $inner = sprintf(
        'cd %s && %s %s compose %s > last_cmd.log 2>&1; ec=$?; '
        . 'printf \'{"result":"%%s","exit_code":%%d,"operation":"%s","timestamp":"%%s"}\' '
        . '"$([ "$ec" -eq 0 ] && echo success || echo error)" "$ec" "$(date \'+%%Y-%%m-%%dT%%H:%%M:%%S%%z\')" '
        . '> last_result.json; '
        . '[ "$ec" -eq 0 ] && /usr/bin/php %s %s >/dev/null 2>&1; '
        . 'printf \'[%%s] action=%s stack=%s result=%%s ip=%s\n\' "$(date -Iseconds)" '
        . '"$([ "$ec" -eq 0 ] && echo ok || echo fail)" >> %s; '
        . 'rm -f .op-running',
        escapeshellarg($dir),
        PATH_ENV,
        DOCKER,
        $args,
        $op,
        escapeshellarg(__DIR__ . '/update-status.php'),
        escapeshellarg($dir),
        $op,
        basename($dir),
        escapeshellarg((string) ($_SERVER['REMOTE_ADDR'] ?? '-')),
        escapeshellarg(AUDIT_LOG)
    );
    exec('nohup sh -c ' . escapeshellarg($inner) . ' > /dev/null 2>&1 &');
}

// ==================== docker 容器更新(续 147,异步带 pull 进度日志) ====================

/**
 * 【续 147】按容器名找 webGui 用户模板(update_container 无模板会静默跳过,必须预检)。
 * 与 DockerTemplates::getUserTemplate 同语义:扫 templates-user/my-*.xml,
 * 按模板内 <Name> 与容器名精确匹配(不是按文件名)。
 */
function findUserTemplate(string $name): ?string
{
    foreach (glob(TEMPLATE_USER_DIR . '/my-*.xml') ?: [] as $file) {
        $xml = (string) @file_get_contents($file);
        if (preg_match('/<Name>\s*([^<]+?)\s*<\/Name>/', $xml, $m) && $m[1] === $name) {
            return $file;
        }
    }
    return null;
}

function userTemplateMatchesUrl(?string $path, string $url): bool
{
    if ($path === null) {
        return false;
    }
    $dom = @simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
    return $dom instanceof SimpleXMLElement && hash_equals($url, trim((string) $dom->TemplateURL));
}

/**
 * 【续 147】生成 docker 更新异步任务脚本(每次调用重写,自更新):
 *  1) 记更新前 容器ID/镜像ID/image ref
 *  2) docker pull(Config.Image 与模板 <Repository> 去重)—— 层进度真落日志(耗时大头;
 *     官方 update_container 的 pull 进度走 nchan 拿不到,故自己先拉)
 *  3) 官方 update_container 重建(镜像已就位,秒级;前后自打标记行)
 *  4) 兜底校验:容器 ID 与镜像 ID 都不变 = 脚本静默无动作 → error(exit 28)
 *  5) result.json(与 compose last_result.json 同构)→ 成功调 update-status.php --image
 *     回写更新缓存 → 审计 → 放锁
 */
function dockerContainerLockPath(string $name): string
{
    return DOCKER_EDIT_DIR . '/' . $name . '.lock';
}

function ensureDockerEditDir(): void
{
    if (!is_dir(DOCKER_EDIT_DIR) && !@mkdir(DOCKER_EDIT_DIR, 0700, true) && !is_dir(DOCKER_EDIT_DIR)) {
        fail(500, '容器任务目录创建失败');
    }
    @chmod(DOCKER_EDIT_DIR, 0700);
}

/** 参数编辑与镜像更新共用一把原子锁，消除双向 check-then-act 竞态。 */
function acquireDockerContainerLock(string $name, string $owner): string
{
    ensureDockerEditDir();
    $path = dockerContainerLockPath($name);
    $lock = @fopen($path, 'x');
    if ($lock === false) {
        fail(409, '该容器有参数编辑或镜像更新正在进行中,请等待完成');
    }
    if (@fwrite($lock, $owner) === false) {
        @fclose($lock);
        @unlink($path);
        fail(500, '容器任务锁写入失败');
    }
    fclose($lock);
    return $path;
}

function releaseDockerContainerLock(string $path, string $owner): void
{
    if (trim((string) @file_get_contents($path)) === $owner) @unlink($path);
}

function dockerUpdateRequestId(mixed $value): string
{
    if ($value === null || $value === '') return bin2hex(random_bytes(16));
    if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) {
        fail(400, 'requestId 必须是 32 位小写十六进制');
    }
    return $value;
}

function dockerUpdateExpectedId(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value)) fail(400, 'expectedId 格式无效');
    $id = str_starts_with($value, 'container:') ? substr($value, 10) : $value;
    if (!preg_match('/^[a-f0-9]{12,64}$/iD', $id)) fail(400, 'expectedId 格式无效');
    return strtolower($id);
}

/** 更新只接受当前仍存在的普通 Docker 容器;客户端 ID 只作额外冲突检查。 */
function dockerUpdateContainer(string $name, ?string $expectedId): array
{
    $lines = [];
    $code = 1;
    exec(
        PATH_ENV . ' ' . DOCKER . ' inspect --format '
        . escapeshellarg('{{.Id}}|{{.State.Status}}|{{with index .Config.Labels "com.docker.compose.project"}}{{.}}{{end}}')
        . ' ' . escapeshellarg($name) . ' 2>/dev/null',
        $lines,
        $code
    );
    if ($code !== 0) fail(404, '容器不存在: ' . $name);
    [$id, $status, $project] = array_pad(explode('|', trim((string) ($lines[0] ?? '')), 3), 3, '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $id)) fail(503, '无法确认容器身份,已取消更新');
    if ($expectedId !== null && !hash_equals($expectedId, strtolower($id))) {
        fail(409, '容器已被外部替换,请刷新列表后重新确认更新');
    }
    if ($project !== '') fail(409, '该容器由 Compose 管理,请使用所属栈更新');
    if (in_array(strtolower($status), ['paused', 'restarting', 'removing'], true)) {
        fail(409, '容器当前状态不适合更新,请先恢复运行或停止');
    }
    return ['id' => strtolower($id), 'status' => $status];
}

function dockerUpdateReleaseLocks(string $dir, string $name, string $jobId): void
{
    $opLock = $dir . '/op-lock';
    if (trim((string) @file_get_contents($opLock)) === $jobId) @unlink($opLock);
    releaseDockerContainerLock(dockerContainerLockPath($name), 'update:' . $jobId);
}

/**
 * 同一独立进程组中的任一非僵尸进程存活都视为任务仍活跃。
 * worker PID 另校验启动时间和 jobId,避免 PID 复用;worker 消失后子进程仍可继续守住任务。
 */
function dockerUpdatePidAlive(int $pid): bool
{
    if ($pid <= 0) return false;
    $raw = @file_get_contents('/proc/' . $pid . '/stat');
    if (!is_string($raw)) return false;
    $close = strrpos($raw, ')');
    if ($close === false) return false;
    $fields = preg_split('/\s+/', trim(substr($raw, $close + 1))) ?: [];
    return ($fields[0] ?? 'Z') !== 'Z';
}

function dockerUpdateProcessGroupAlive(array $status): ?bool
{
    $pid = (int) ($status['pid'] ?? 0);
    $pgid = (int) ($status['pgid'] ?? 0);
    $jobId = (string) ($status['id'] ?? '');
    if (!is_dir('/proc')) return null;
    if ($pid <= 0 || $pgid <= 0 || !preg_match('/^[a-f0-9]{32}$/D', $jobId)) return null;
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
        $raw = @file_get_contents($path);
        if (!is_string($raw)) continue;
        $close = strrpos($raw, ')');
        if ($close === false) continue;
        $fields = preg_split('/\s+/', trim(substr($raw, $close + 1))) ?: [];
        if ((int) ($fields[2] ?? 0) !== $pgid) continue;
        if (($fields[0] ?? '') === 'Z') continue;
        $processPid = (int) basename(dirname($path));
        if ($processPid === $pid) {
            $expectedStart = (string) ($status['startedTicks'] ?? '');
            $actualStart = (string) ($fields[19] ?? '');
            if ($expectedStart !== '' && !hash_equals($expectedStart, $actualStart)) continue;
            $cmdline = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
            if (!str_contains($cmdline, $jobId)) continue;
        }
        return true;
    }
    return false;
}

/** 读取并必要时回收任务;只有整个进程组消失才允许把异常运行态落成 unknown。 */
function readDockerUpdateStatus(string $name): array
{
    $dir = DOCKER_UPDATE_DIR . '/' . $name;
    $status = readJsonFile($dir . '/status.json');
    $result = readJsonFile($dir . '/result.json');
    $lockExists = file_exists($dir . '/op-lock');
    if (!is_array($status) || !preg_match('/^[a-f0-9]{32}$/D', (string) ($status['id'] ?? ''))) {
        // 部署前的旧 worker 没有 status.json,继续按旧锁观察,绝不贸然回收。
        $running = $lockExists && $result === null;
        return [
            'jobId' => null,
            'requestId' => null,
            'state' => $result !== null ? (($result['result'] ?? '') === 'success' ? 'succeeded' : 'failed')
                : ($running ? 'running' : 'unknown'),
            'running' => $running,
            'result' => $result,
            'message' => null,
        ];
    }

    $jobId = (string) $status['id'];
    $state = (string) ($status['state'] ?? 'queued');
    $requestId = (string) ($status['requestId'] ?? '');
    $terminal = in_array($state, ['succeeded', 'failed', 'unknown'], true);
    $alive = dockerUpdateProcessGroupAlive($status);
    if ($terminal) {
        if ($alive === false) dockerUpdateReleaseLocks($dir, $name, $jobId);
        return [
            'jobId' => $jobId,
            'requestId' => $requestId !== '' ? $requestId : null,
            'state' => $state,
            'running' => false,
            'result' => $result,
            'message' => is_string($status['message'] ?? null) ? $status['message'] : null,
        ];
    }

    if ($state === 'queued' && (int) ($status['pid'] ?? 0) <= 0) {
        // worker 启动后才回写 PID/PGID；此间无进程可查，但 op-lock 已生效。
        // 不按宽限期猜死放锁，避免同容器/项目被并发执行。
        if ($lockExists) $alive = true;
    } elseif ($alive === null && $lockExists) {
        // 无法读取进程信息时 fail closed,不因为猜测而释放互斥锁。
        $alive = true;
    }

    if ($alive === true) {
        return [
            'jobId' => $jobId,
            'requestId' => $requestId !== '' ? $requestId : null,
            'state' => $state === 'queued' ? 'queued' : 'running',
            'running' => true,
            'result' => $result,
            'message' => null,
        ];
    }

    if (is_array($result)) {
        $state = ($result['result'] ?? '') === 'success' ? 'succeeded' : 'failed';
        $status['state'] = $state;
        $status['updatedAt'] = time();
        writePluginJson($dir . '/status.json', $status);
    } else {
        $state = 'unknown';
        $status['state'] = 'unknown';
        $status['updatedAt'] = time();
        $status['message'] = '更新进程已异常退出,无法确认镜像拉取或容器重建结果;请检查日志与容器状态后再操作';
        writePluginJson($dir . '/status.json', $status);
    }
    dockerUpdateReleaseLocks($dir, $name, $jobId);
    return [
        'jobId' => $jobId,
        'requestId' => $requestId !== '' ? $requestId : null,
        'state' => $state,
        'running' => false,
        'result' => is_array($result) ? $result : null,
        'message' => is_string($status['message'] ?? null) ? $status['message'] : null,
    ];
}

/** 生成独立进程组 worker;身份、Compose 归属和模板 revision 在 pull 前后都复核。 */
function dockerUpdateJobScript(array $job): string
{
    $name = (string) $job['name'];
    $jobId = (string) $job['id'];
    return '#!/bin/sh' . "\n"
        . 'set -u' . "\n"
        . 'opLock=' . escapeshellarg((string) $job['dir'] . '/op-lock') . "\n"
        . 'containerLock=' . escapeshellarg((string) $job['containerLock']) . "\n"
        . 'lockOwner=' . escapeshellarg('update:' . $jobId) . "\n"
        . 'statusPath=' . escapeshellarg((string) $job['dir'] . '/status.json') . "\n"
        . 'resultPath=' . escapeshellarg((string) $job['dir'] . '/result.json') . "\n"
        . 'jobId=' . escapeshellarg($jobId) . "\n"
        . 'requestId=' . escapeshellarg((string) $job['requestId']) . "\n"
        . 'createdAt=' . (int) $job['createdAt'] . "\n"
        . 'name=' . escapeshellarg($name) . "\n"
        . 'tmpl=' . escapeshellarg((string) $job['templatePath']) . "\n"
        . 'expectedId=' . escapeshellarg((string) $job['expectedId']) . "\n"
        . 'templateRevision=' . escapeshellarg((string) $job['templateRevision']) . "\n"
        . 'dockerBin=' . escapeshellarg(DOCKER) . "\n"
        . 'updateScript=' . escapeshellarg(DOCKER_UPDATE_SCRIPT) . "\n"
        . 'setsidBin=' . escapeshellarg(DOCKER_UPDATE_SETSID) . "\n"
        . 'updateStatusScript=' . escapeshellarg(__DIR__ . '/update-status.php') . "\n"
        . 'auditLog=' . escapeshellarg(AUDIT_LOG) . "\n"
        . 'requestIp=' . escapeshellarg((string) $job['ip']) . "\n"
        . 'if [ "${1:-}" != "--start" ] || [ "${2:-}" != "$jobId" ]; then exit 64; fi' . "\n"
        . 'if [ "${3:-}" != "--grouped" ]; then exec "$setsidBin" /bin/sh "$0" --start "$jobId" --grouped; fi' . "\n"
        . 'cd ' . escapeshellarg((string) $job['dir']) . ' || exit 1' . "\n"
        . 'exec > update.log 2>&1' . "\n"
        . 'write_state() {' . "\n"
        . '  state="$1"; statRest="$(sed "s/.*) //" "/proc/$$/stat" 2>/dev/null)"' . "\n"
        . '  set -- $statRest; pgid="${3:-0}"; started="${20:-}"; tmp="$statusPath.tmp.$$"' . "\n"
        . '  printf \'%s\n\' "{\"id\":\"$jobId\",\"name\":\"$name\",\"requestId\":\"$requestId\",\"expectedId\":\"$expectedId\",\"templateRevision\":\"$templateRevision\",\"createdAt\":$createdAt,\"updatedAt\":$(date +%s),\"pid\":$$,\"pgid\":${pgid:-0},\"startedTicks\":\"${started:-}\",\"state\":\"$state\"}" > "$tmp" && mv -f "$tmp" "$statusPath"' . "\n"
        . '}' . "\n"
        . 'write_result() {' . "\n"
        . '  ec="$1"; [ "$ec" -eq 0 ] && result=success || result=error; tmp="$resultPath.tmp.$$"' . "\n"
        . '  printf \'{"result":"%s","exit_code":%d,"operation":"dockerupdate","timestamp":"%s"}\' "$result" "$ec" "$(date +%Y-%m-%dT%H:%M:%S%z)" > "$tmp" && mv -f "$tmp" "$resultPath"' . "\n"
        . '}' . "\n"
        . 'cleanup() {' . "\n"
        . '  if [ -f "$opLock" ] && [ "$(cat "$opLock")" = "$jobId" ]; then rm -f "$opLock"; fi' . "\n"
        . '  if [ -f "$containerLock" ] && [ "$(cat "$containerLock")" = "$lockOwner" ]; then rm -f "$containerLock"; fi' . "\n"
        . '}' . "\n"
        . 'trap cleanup EXIT' . "\n"
        . 'write_state running' . "\n"
        . 'load_identity() {' . "\n"
        . '  line="$("$dockerBin" inspect --format \'{{.Id}}|{{.Image}}|{{.Config.Image}}|{{with index .Config.Labels "com.docker.compose.project"}}{{.}}{{end}}|{{.State.Status}}\' "$name" 2>/dev/null)" || return 1' . "\n"
        . '  currentId="$(printf \'%s\n\' "$line" | cut -d\'|\' -f1)"; currentImageId="$(printf \'%s\n\' "$line" | cut -d\'|\' -f2)"' . "\n"
        . '  currentImageRef="$(printf \'%s\n\' "$line" | cut -d\'|\' -f3)"; currentProject="$(printf \'%s\n\' "$line" | cut -d\'|\' -f4)"' . "\n"
        . '  currentStatus="$(printf \'%s\n\' "$line" | cut -d\'|\' -f5)"' . "\n"
        . '}' . "\n"
        . 'assert_identity() {' . "\n"
        . '  load_identity || { echo "!! 无法读取容器身份,已中止"; return 1; }' . "\n"
        . '  [ "$currentId" = "$expectedId" ] || { echo "!! 容器已被外部替换或删除,不会操作同名新容器"; return 1; }' . "\n"
        . '  [ -z "$currentProject" ] || { echo "!! 容器已变为 Compose 管理,已中止普通更新"; return 1; }' . "\n"
        . '  case "$(printf %s "$currentStatus" | tr "[:upper:]" "[:lower:]")" in paused|restarting|removing) echo "!! 容器状态已变化,已中止"; return 1;; esac' . "\n"
        . '  [ "$(sha256sum "$tmpl" 2>/dev/null | awk "{print \\$1}")" = "$templateRevision" ] || { echo "!! 用户模板在更新期间被修改,已中止"; return 1; }' . "\n"
        . '}' . "\n"
        . 'ec=0; oldcid=""; oldiid=""; img=""' . "\n"
        . 'if assert_identity; then oldcid="$currentId"; oldiid="$currentImageId"; img="$currentImageRef"; else ec=75; fi' . "\n"
        . 'echo "[$(date +%H:%M:%S)] 更新容器 $name"; echo "镜像: $img"' . "\n"
        . 'trep=$(sed -n \'s:.*<Repository>\\(.*\\)</Repository>.*:\1:p\' "$tmpl" | head -1 | tr -d \'[:space:]\')' . "\n"
        . 'pulls="$img"; [ -n "$trep" ] && [ "$trep" != "$img" ] && pulls="$img $trep"' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  for ref in $pulls; do echo; echo "== 拉取镜像 $ref =="; "$dockerBin" pull "$ref" || { ec=$?; break; }; done' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then assert_identity || ec=75; fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  echo; echo "== 重建容器 =="; "$updateScript" "$name"; uec=$?' . "\n"
        . '  echo "(update_container exit $uec)"; [ "$uec" -ne 0 ] && ec=$uec' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  if load_identity; then newcid="$currentId"; newiid="$currentImageId"; else echo "!! 更新后无法读取容器"; ec=41; fi' . "\n"
        . '  if [ "$ec" -eq 0 ] && [ "$newcid" = "$oldcid" ] && [ "$newiid" = "$oldiid" ]; then echo "!! 未检测到更新动作(容器/镜像 ID 均未变)"; ec=28; fi' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then echo; echo "== 更新完成 =="; /usr/bin/php "$updateStatusScript" --image "$img" >/dev/null 2>&1; fi' . "\n"
        . 'case "$ec" in \'\'|*[!0-9]*) ec=1;; esac' . "\n"
        . 'write_result "$ec"' . "\n"
        . '[ "$ec" -eq 0 ] && write_state succeeded || write_state failed' . "\n"
        . 'printf \'[%s] action=dockerupdate stack=%s result=%s ip=%s\n\' "$(date +%Y-%m-%dT%H:%M:%S%z)" "$name" "$([ "$ec" -eq 0 ] && echo ok || echo fail)" "$requestIp" >> "$auditLog"' . "\n"
        . 'exit "$ec"' . "\n";
}

/** 返回 jobId/requestId;同一 requestId 的恢复由路由在调用本函数前处理。 */
function runDockerUpdateAsync(string $name, string $tmpl, array $container,
    ?string $clientExpectedId, string $requestId): array
{
    if (!is_executable(DOCKER_UPDATE_SETSID)) fail(503, '宿主缺少 setsid,无法安全跟踪更新任务');
    $templateRevision = @hash_file('sha256', $tmpl);
    if (!is_string($templateRevision)) fail(422, '用户模板读取失败,已取消更新');
    $dir = DOCKER_UPDATE_DIR . '/' . $name;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fail(500, '工作目录创建失败');
    $existing = readDockerUpdateStatus($name);
    if ($existing['running'] === true) {
        audit('dockerupdate', $name, 'fail');
        fail(409, '该容器有更新正在进行中,请等待完成');
    }

    $jobId = bin2hex(random_bytes(16));
    $lockOwner = 'update:' . $jobId;
    $containerLock = acquireDockerContainerLock($name, $lockOwner);
    $queued = false;
    $opOwned = false;
    $lockFile = $dir . '/op-lock';
    register_shutdown_function(static function () use (&$queued, &$opOwned, $lockFile, $jobId, $containerLock, $lockOwner): void {
        if ($queued) return;
        if ($opOwned && trim((string) @file_get_contents($lockFile)) === $jobId) @unlink($lockFile);
        releaseDockerContainerLock($containerLock, $lockOwner);
    });
    $lock = @fopen($lockFile, 'x');
    if ($lock === false) {
        releaseDockerContainerLock($containerLock, $lockOwner);
        audit('dockerupdate', $name, 'fail');
        fail(409, '该容器有更新正在进行中,请等待完成');
    }
    if (@fwrite($lock, $jobId) === false) {
        @fclose($lock);
        @unlink($lockFile);
        fail(500, '更新任务锁写入失败');
    }
    fclose($lock);
    $opOwned = true;

    $createdAt = time();
    $job = [
        'id' => $jobId,
        'name' => $name,
        'dir' => $dir,
        'containerLock' => $containerLock,
        'requestId' => $requestId,
        'createdAt' => $createdAt,
        'templatePath' => $tmpl,
        'templateRevision' => $templateRevision,
        'expectedId' => (string) $container['id'],
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
    ];
    try {
        @unlink($dir . '/result.json');
        writePluginJson($dir . '/status.json', [
            'id' => $jobId,
            'name' => $name,
            'requestId' => $requestId,
            'expectedId' => $container['id'],
            'templateRevision' => $templateRevision,
            'createdAt' => $createdAt,
            'updatedAt' => $createdAt,
            'pid' => 0,
            'pgid' => 0,
            'startedTicks' => '',
            'state' => 'queued',
        ]);
        $script = $dir . '/job.sh';
        if (@file_put_contents($script, dockerUpdateJobScript($job), LOCK_EX) === false) {
            throw new RuntimeException('任务脚本写入失败');
        }
        @chmod($script, 0700);
        @file_put_contents($dir . '/update.log', '', LOCK_EX);
    } catch (Throwable $e) {
        fail(500, $e->getMessage());
    }

    $spawnOutput = [];
    $spawnCode = 1;
    exec(PATH_ENV . ' nohup /bin/sh ' . escapeshellarg($dir . '/job.sh')
        . ' --start ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 < /dev/null & echo $!',
        $spawnOutput, $spawnCode);
    usleep(150000);
    $launchPid = (int) end($spawnOutput);
    $latest = readJsonFile($dir . '/status.json');
    $latestState = is_array($latest) ? (string) ($latest['state'] ?? '') : '';
    $started = in_array($latestState, ['running', 'succeeded', 'failed'], true);
    $launcherAlive = dockerUpdatePidAlive($launchPid);
    if ($spawnCode !== 0 || (!$started && !$launcherAlive)) {
        $error = ['result' => 'error', 'exit_code' => 1, 'operation' => 'dockerupdate', 'timestamp' => date('c')];
        try {
            writePluginJson($dir . '/result.json', $error);
            if (is_array($latest)) {
                $latest['state'] = 'failed';
                $latest['updatedAt'] = time();
                $latest['message'] = '更新后台 worker 启动失败,未执行镜像拉取或容器重建';
                writePluginJson($dir . '/status.json', $latest);
            }
        } catch (Throwable) {
            // 启动失败仍以清理互斥锁和返回明确错误为主。
        }
        if (trim((string) @file_get_contents($lockFile)) === $jobId) @unlink($lockFile);
        releaseDockerContainerLock($containerLock, $lockOwner);
        $queued = true;
        audit('dockerupdate', $name, 'fail');
        fail(500, '更新后台 worker 启动失败,未执行更新');
    }
    $queued = true;
    audit('dockerupdate', $name, 'queued');
    return ['async' => true, 'jobId' => $jobId, 'requestId' => $requestId];
}

// ==================== 宿主后端自升级 ====================

/** 只允许语义化三段版本;不许通过客户端指定任意 URL、分支或低于当前后端的版本。 */
function hostUpdateTargetVersion(mixed $value): string
{
    if (!is_string($value)) fail(400, 'targetVersion 必须是 x.y.z 版本号');
    $version = ltrim(trim($value), 'vV');
    if (!preg_match('/^\d+\.\d+\.\d+$/D', $version)) {
        fail(400, 'targetVersion 必须是 x.y.z 版本号');
    }
    if (version_compare($version, APP_VERSION, '<')) {
        fail(400, '不允许通过此入口降级宿主后端');
    }
    return $version;
}

function readHostUpdateStatus(): array
{
    $dir = HOST_UPDATE_DIR;
    $status = readJsonFile($dir . '/status.json');
    $result = readJsonFile($dir . '/result.json');
    $lockExists = file_exists($dir . '/op-lock');
    if (!is_array($status) || !preg_match('/^[a-f0-9]{32}$/D', (string) ($status['id'] ?? ''))) {
        $running = $lockExists && $result === null;
        return [
            'jobId' => null, 'requestId' => null, 'targetVersion' => null,
            'state' => $result !== null ? (($result['result'] ?? '') === 'success' ? 'succeeded' : 'failed')
                : ($running ? 'running' : 'unknown'),
            'running' => $running, 'result' => $result, 'message' => null,
        ];
    }
    $jobId = (string) $status['id'];
    $state = (string) ($status['state'] ?? 'queued');
    $requestId = (string) ($status['requestId'] ?? '');
    $targetVersion = (string) ($status['targetVersion'] ?? '');
    $terminal = in_array($state, ['succeeded', 'failed', 'unknown'], true);
    $alive = dockerUpdateProcessGroupAlive($status);
    if ($terminal) {
        if ($alive === false && trim((string) @file_get_contents($dir . '/op-lock')) === $jobId) {
            @unlink($dir . '/op-lock');
        }
        return [
            'jobId' => $jobId, 'requestId' => $requestId !== '' ? $requestId : null,
            'targetVersion' => $targetVersion !== '' ? $targetVersion : null,
            'state' => $state, 'running' => false, 'result' => $result,
            'message' => is_string($status['message'] ?? null) ? $status['message'] : null,
        ];
    }
    if ($state === 'queued' && (int) ($status['pid'] ?? 0) <= 0) {
        if ($lockExists) $alive = true;
    } elseif ($alive === null && $lockExists) {
        $alive = true;
    }
    if ($alive === true) {
        return [
            'jobId' => $jobId, 'requestId' => $requestId !== '' ? $requestId : null,
            'targetVersion' => $targetVersion !== '' ? $targetVersion : null,
            'state' => $state === 'queued' ? 'queued' : 'running',
            'running' => true, 'result' => null, 'message' => null,
        ];
    }
    if (is_array($result)) {
        $state = ($result['result'] ?? '') === 'success' ? 'succeeded' : 'failed';
    } else {
        $state = 'unknown';
        $status['message'] = '宿主升级进程已异常退出,无法确认安装结果;请查看日志并核对后端版本后再操作';
    }
    $status['state'] = $state;
    $status['updatedAt'] = time();
    writePluginJson($dir . '/status.json', $status);
    if (trim((string) @file_get_contents($dir . '/op-lock')) === $jobId) @unlink($dir . '/op-lock');
    return [
        'jobId' => $jobId, 'requestId' => $requestId !== '' ? $requestId : null,
        'targetVersion' => $targetVersion !== '' ? $targetVersion : null,
        'state' => $state, 'running' => false, 'result' => $result,
        'message' => is_string($status['message'] ?? null) ? $status['message'] : null,
    ];
}

/**
 * worker 在独立进程组运行,替换 api.php 不会中断安装。
 * 发布包只来自官方部署仓固定 vX.Y.Z tag;SHA256SUMS 必须覆盖全部待执行/安装文件。
 * --local-only 让安装器只使用已校验 payload,--preserve-key 保留现有 API key 哈希。
 * 安装失败或版本复核失败时恢复 boot/exec 管理文件和 go,避免半升级状态。
 */
function hostUpdateJobScript(array $job): string
{
    $jobId = (string) $job['id'];
    return '#!/bin/sh' . "\n"
        . 'set -u' . "\n"
        . 'opLock=' . escapeshellarg(HOST_UPDATE_DIR . '/op-lock') . "\n"
        . 'statusPath=' . escapeshellarg(HOST_UPDATE_DIR . '/status.json') . "\n"
        . 'resultPath=' . escapeshellarg(HOST_UPDATE_DIR . '/result.json') . "\n"
        . 'jobId=' . escapeshellarg($jobId) . "\n"
        . 'requestId=' . escapeshellarg((string) $job['requestId']) . "\n"
        . 'targetVersion=' . escapeshellarg((string) $job['targetVersion']) . "\n"
        . 'createdAt=' . (int) $job['createdAt'] . "\n"
        . 'repoBase=' . escapeshellarg(HOST_UPDATE_REPO) . "\n"
        . 'if [ "${1:-}" != "--start" ] || [ "${2:-}" != "$jobId" ]; then exit 64; fi' . "\n"
        . 'if [ "${3:-}" != "--grouped" ]; then exec /usr/bin/setsid /bin/sh "$0" --start "$jobId" --grouped; fi' . "\n"
        . 'cd ' . escapeshellarg(HOST_UPDATE_DIR) . ' || exit 1' . "\n"
        . 'exec > update.log 2>&1' . "\n"
        . 'write_state() {' . "\n"
        . '  state="$1"; message="${2:-}"; statRest="$(sed "s/.*) //" "/proc/$$/stat" 2>/dev/null)"' . "\n"
        . '  set -- $statRest; pgid="${3:-0}"; started="${20:-}"; tmp="$statusPath.tmp.$$"' . "\n"
        . '  printf \'%s\n\' "{\"id\":\"$jobId\",\"requestId\":\"$requestId\",\"targetVersion\":\"$targetVersion\",\"createdAt\":$createdAt,\"updatedAt\":$(date +%s),\"pid\":$$,\"pgid\":${pgid:-0},\"startedTicks\":\"${started:-}\",\"state\":\"$state\",\"message\":\"$message\"}" > "$tmp" && mv -f "$tmp" "$statusPath"' . "\n"
        . '}' . "\n"
        . 'write_result() {' . "\n"
        . '  ec="$1"; [ "$ec" -eq 0 ] && result=success || result=error; tmp="$resultPath.tmp.$$"' . "\n"
        . '  printf \'{"result":"%s","exit_code":%d,"operation":"hostupdate","timestamp":"%s"}\' "$result" "$ec" "$(date +%Y-%m-%dT%H:%M:%S%z)" > "$tmp" && mv -f "$tmp" "$resultPath"' . "\n"
        . '}' . "\n"
        . 'cleanup() { if [ -f "$opLock" ] && [ "$(cat "$opLock")" = "$jobId" ]; then rm -f "$opLock"; fi; }' . "\n"
        . 'trap cleanup EXIT' . "\n"
        . 'write_state running' . "\n"
        . 'ec=0; prepared=0; stage="$PWD/payload"; backup="$PWD/backup"' . "\n"
        . 'rm -rf "$stage"; mkdir -p "$stage" || ec=70' . "\n"
        . 'base="$repoBase/v$targetVersion/compose-api"' . "\n"
        . 'files="install-compose-api.sh api.php plugin-worker.php update-status.php update-file-index.sh register-index-cron.sh SHA256SUMS"' . "\n"
        . 'echo "[$(date +%H:%M:%S)] 下载宿主后端 v$targetVersion 固定发布包"' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  for f in $files; do' . "\n"
        . '    echo "下载 $f"; curl -fsSL --proto "=https" --proto-redir "=https" --connect-timeout 10 --max-time 180 "$base/$f" -o "$stage/$f" || { ec=$?; break; }' . "\n"
        . '  done' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  echo "校验发布包 SHA-256"; (cd "$stage" && sha256sum -c SHA256SUMS) || ec=$?' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then php -l "$stage/api.php" >/dev/null && php -l "$stage/plugin-worker.php" >/dev/null && php -l "$stage/update-status.php" >/dev/null || ec=$?; fi' . "\n"
        . 'boot=/boot/config/plugins/unraid-mobile; execDir=/usr/local/emhttp/plugins/compose.manager' . "\n"
        . 'managed="api.php plugin-worker.php update-status.php update-file-index.sh register-index-cron.sh"' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  rm -rf "$backup"; mkdir -p "$backup/boot" "$backup/exec" || ec=70' . "\n"
        . '  for f in $managed; do' . "\n"
        . '    [ ! -f "$boot/$f" ] || cp -p "$boot/$f" "$backup/boot/$f" || { ec=$?; break; }' . "\n"
        . '    [ ! -f "$execDir/$f" ] || cp -p "$execDir/$f" "$backup/exec/$f" || { ec=$?; break; }' . "\n"
        . '  done' . "\n"
        . '  [ ! -f /boot/config/go ] || cp -p /boot/config/go "$backup/go" || ec=$?' . "\n"
        . '  [ "$ec" -ne 0 ] || prepared=1' . "\n"
        . 'fi' . "\n"
        . 'rollback() {' . "\n"
        . '  echo "安装失败,恢复升级前文件"' . "\n"
        . '  for f in $managed; do' . "\n"
        . '    if [ -f "$backup/boot/$f" ]; then cp -p "$backup/boot/$f" "$boot/$f"; else rm -f "$boot/$f"; fi' . "\n"
        . '    if [ -f "$backup/exec/$f" ]; then cp -p "$backup/exec/$f" "$execDir/$f"; else rm -f "$execDir/$f"; fi' . "\n"
        . '  done' . "\n"
        . '  if [ -f "$backup/go" ]; then cp -p "$backup/go" /boot/config/go; else rm -f /boot/config/go; fi' . "\n"
        . '}' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  echo "执行宿主安装器(保留 API key 与 License 凭证)"' . "\n"
        . '  bash "$stage/install-compose-api.sh" --local-only --preserve-key -y; ec=$?' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  grep -q "const APP_VERSION = \'$targetVersion\';" "$boot/api.php" || ec=65' . "\n"
        . '  cmp -s "$boot/api.php" "$execDir/api.php" || ec=66' . "\n"
        . '  php -l "$execDir/api.php" >/dev/null || ec=$?' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -ne 0 ]; then' . "\n"
        . '  if [ "$prepared" = "1" ]; then rollback; write_state failed "安装或版本复核失败,已尝试恢复升级前文件"; else write_state failed "下载或校验失败,未改动宿主后端"; fi' . "\n"
        . 'else write_state succeeded; fi' . "\n"
        . 'write_result "$ec"' . "\n"
        . 'printf \'[%s] action=hostupdate target=%s result=%s\n\' "$(date +%Y-%m-%dT%H:%M:%S%z)" "$targetVersion" "$([ "$ec" -eq 0 ] && echo ok || echo fail)" >> ' . escapeshellarg(AUDIT_LOG) . "\n"
        . 'exit "$ec"' . "\n";
}

function runHostUpdateAsync(string $targetVersion, string $requestId): array
{
    if (!is_executable(DOCKER_UPDATE_SETSID)) fail(503, '宿主缺少 setsid,无法安全跟踪升级任务');
    if (!is_dir(HOST_UPDATE_DIR) && !@mkdir(HOST_UPDATE_DIR, 0700, true) && !is_dir(HOST_UPDATE_DIR)) {
        fail(500, '宿主升级任务目录创建失败');
    }
    $existing = readHostUpdateStatus();
    if (is_string($existing['requestId'] ?? null) && hash_equals($requestId, $existing['requestId'])) {
        if (($existing['targetVersion'] ?? null) !== $targetVersion) {
            fail(409, 'requestId 已用于另一个目标版本,不能重复提交');
        }
        return [
            'async' => true, 'jobId' => $existing['jobId'], 'requestId' => $requestId,
            'targetVersion' => $targetVersion, 'duplicate' => true,
        ];
    }
    if ($existing['running'] === true) fail(409, '宿主后端已有升级任务进行中');

    $jobId = bin2hex(random_bytes(16));
    $lockFile = HOST_UPDATE_DIR . '/op-lock';
    $lock = @fopen($lockFile, 'x');
    if ($lock === false) fail(409, '宿主后端已有升级任务进行中');
    if (@fwrite($lock, $jobId) === false) {
        @fclose($lock); @unlink($lockFile);
        fail(500, '宿主升级任务锁写入失败');
    }
    fclose($lock);
    $createdAt = time();
    $job = [
        'id' => $jobId, 'requestId' => $requestId, 'targetVersion' => $targetVersion,
        'createdAt' => $createdAt,
    ];
    $queued = false;
    register_shutdown_function(static function () use (&$queued, $lockFile, $jobId): void {
        if (!$queued && trim((string) @file_get_contents($lockFile)) === $jobId) @unlink($lockFile);
    });
    @unlink(HOST_UPDATE_DIR . '/result.json');
    writePluginJson(HOST_UPDATE_DIR . '/status.json', [
        'id' => $jobId, 'requestId' => $requestId, 'targetVersion' => $targetVersion,
        'createdAt' => $createdAt, 'updatedAt' => $createdAt,
        'pid' => 0, 'pgid' => 0, 'startedTicks' => '', 'state' => 'queued',
    ]);
    $script = HOST_UPDATE_DIR . '/job.sh';
    if (@file_put_contents($script, hostUpdateJobScript($job), LOCK_EX) === false) fail(500, '升级任务脚本写入失败');
    @chmod($script, 0700);
    @file_put_contents(HOST_UPDATE_DIR . '/update.log', '', LOCK_EX);
    $spawnOutput = [];
    $spawnCode = 1;
    exec(PATH_ENV . ' nohup /bin/sh ' . escapeshellarg($script)
        . ' --start ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 < /dev/null & echo $!',
        $spawnOutput, $spawnCode);
    usleep(150000);
    $launchPid = (int) end($spawnOutput);
    $latest = readJsonFile(HOST_UPDATE_DIR . '/status.json');
    $latestState = is_array($latest) ? (string) ($latest['state'] ?? '') : '';
    $started = in_array($latestState, ['running', 'succeeded', 'failed'], true);
    if ($spawnCode !== 0 || (!$started && !dockerUpdatePidAlive($launchPid))) {
        $error = ['result' => 'error', 'exit_code' => 1, 'operation' => 'hostupdate', 'timestamp' => date('c')];
        writePluginJson(HOST_UPDATE_DIR . '/result.json', $error);
        if (is_array($latest)) {
            $latest['state'] = 'failed';
            $latest['message'] = '宿主升级 worker 启动失败,未执行安装';
            writePluginJson(HOST_UPDATE_DIR . '/status.json', $latest);
        }
        if (trim((string) @file_get_contents($lockFile)) === $jobId) @unlink($lockFile);
        $queued = true;
        fail(500, '宿主升级 worker 启动失败,未执行安装');
    }
    $queued = true;
    audit('hostupdate', $targetVersion, 'queued');
    return ['async' => true, 'jobId' => $jobId, 'requestId' => $requestId, 'targetVersion' => $targetVersion];
}

// ==================== 外部 Compose 项目更新 ====================

function externalUpdateReleaseLock(string $dir, string $jobId): void
{
    $path = $dir . '/op-lock';
    if (trim((string) @file_get_contents($path)) === $jobId) @unlink($path);
}

/** 与普通更新共用进程组判定;项目目录就是同项目所有锚点容器的互斥锁。 */
function readExternalUpdateStatus(string $project): array
{
    $dir = EXTERNAL_UPDATE_DIR . '/' . $project;
    $status = readJsonFile($dir . '/status.json');
    $result = readJsonFile($dir . '/result.json');
    $lockExists = file_exists($dir . '/op-lock');
    if (!is_array($status) || !preg_match('/^[a-f0-9]{32}$/D', (string) ($status['id'] ?? ''))) {
        $running = $lockExists && $result === null;
        return [
            'jobId' => null,
            'requestId' => null,
            'state' => $result !== null ? (($result['result'] ?? '') === 'success' ? 'succeeded' : 'failed')
                : ($running ? 'running' : 'unknown'),
            'running' => $running,
            'result' => $result,
            'message' => null,
        ];
    }

    $jobId = (string) $status['id'];
    $state = (string) ($status['state'] ?? 'queued');
    $requestId = (string) ($status['requestId'] ?? '');
    $terminal = in_array($state, ['succeeded', 'failed', 'unknown'], true);
    $alive = dockerUpdateProcessGroupAlive($status);
    if ($terminal) {
        if ($alive === false) externalUpdateReleaseLock($dir, $jobId);
        return [
            'jobId' => $jobId,
            'requestId' => $requestId !== '' ? $requestId : null,
            'state' => $state,
            'running' => false,
            'result' => $result,
            'message' => is_string($status['message'] ?? null) ? $status['message'] : null,
        ];
    }
    if ($state === 'queued' && (int) ($status['pid'] ?? 0) <= 0) {
        // worker 启动后才回写 PID/PGID；此间无进程可查，但 op-lock 已生效。
        // 不按宽限期猜死放锁，避免同容器/项目被并发执行。
        if ($lockExists) $alive = true;
    } elseif ($alive === null && $lockExists) {
        $alive = true;
    }
    if ($alive === true) {
        return [
            'jobId' => $jobId,
            'requestId' => $requestId !== '' ? $requestId : null,
            'state' => $state === 'queued' ? 'queued' : 'running',
            'running' => true,
            'result' => $result,
            'message' => null,
        ];
    }
    if (is_array($result)) {
        $state = ($result['result'] ?? '') === 'success' ? 'succeeded' : 'failed';
        $status['state'] = $state;
        $status['updatedAt'] = time();
        writePluginJson($dir . '/status.json', $status);
    } else {
        $state = 'unknown';
        $status['state'] = 'unknown';
        $status['updatedAt'] = time();
        $status['message'] = '外部 Compose 更新进程已异常退出,无法确认 pull 或项目重建结果;请检查日志与容器状态后再操作';
        writePluginJson($dir . '/status.json', $status);
    }
    externalUpdateReleaseLock($dir, $jobId);
    return [
        'jobId' => $jobId,
        'requestId' => $requestId !== '' ? $requestId : null,
        'state' => $state,
        'running' => false,
        'result' => is_array($result) ? $result : null,
        'message' => is_string($status['message'] ?? null) ? $status['message'] : null,
    ];
}

/** requestId 全局定位任务,恢复发生在重新 inspect 之前,成功重建换 ID 后仍可附着。 */
function externalUpdateFindByRequestId(string $requestId): ?array
{
    $found = null;
    foreach (glob(EXTERNAL_UPDATE_DIR . '/*/status.json') ?: [] as $path) {
        $project = basename(dirname($path));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/D', $project)) continue;
        $raw = readJsonFile($path);
        if (!is_array($raw) || ($raw['project'] ?? null) !== $project
            || !is_string($raw['requestId'] ?? null)
            || !hash_equals($requestId, $raw['requestId'])) continue;
        if ($found !== null) fail(409, 'requestId 对应多个外部更新任务,请检查服务端任务目录');
        $found = [
            'project' => $project,
            'dir' => dirname($path),
            'raw' => $raw,
            'status' => readExternalUpdateStatus($project),
        ];
    }
    return $found;
}

/** 409 附着时按当前锚点容器定位项目；不同锚点也能接回同一项目的活动任务。 */
function externalUpdateProjectForContainer(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name)) {
        fail(400, '非法容器名');
    }
    $lines = [];
    $code = 1;
    $format = '{{.Id}}|{{.State.Status}}'
        . '|{{with index .Config.Labels "com.docker.compose.project"}}{{.}}{{end}}'
        . '|{{with index .Config.Labels "com.docker.compose.project.working_dir"}}{{.}}{{end}}'
        . '|{{with index .Config.Labels "com.docker.compose.project.config_files"}}{{.}}{{end}}';
    exec(
        PATH_ENV . ' ' . DOCKER . ' inspect --format ' . escapeshellarg($format)
        . ' ' . escapeshellarg($name) . ' 2>/dev/null',
        $lines,
        $code
    );
    if ($code !== 0) fail(404, '容器不存在: ' . $name);
    $parts = explode('|', trim((string) ($lines[0] ?? '')));
    if (count($parts) !== 5) fail(422, '容器 Compose 标签格式无效');
    $project = $parts[2];
    $workingDir = $parts[3];
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/D', $project)) {
        fail(422, '容器不属于有效的外部 Compose 项目');
    }
    if (externalProjectIsManaged($project, [$workingDir])) {
        fail(409, 'compose.manager 项目请使用所属栈日志');
    }
    return $project;
}

/** 只接受已存在且未解析软链的绝对路径,worker 在 pull 后再次执行同样检查。 */
function externalComposePath(string $path, bool $directory): string
{
    if ($path === '' || strlen($path) > 4096 || !str_starts_with($path, '/')
        || preg_match('/[\x00-\x1F\x7F|]/', $path)) {
        fail(422, 'Compose working_dir/config_files 必须是受限制的绝对路径');
    }
    $real = realpath($path);
    if (!is_string($real) || !hash_equals($path, $real)
        || ($directory ? !is_dir($real) : !is_file($real))) {
        fail(422, 'Compose 路径不存在、类型错误或包含软链,已取消更新');
    }
    return $real;
}

/** 实时 inspect 锚点;所有 Compose 参数只来自服务端标签,不接收客户端路径或命令。 */
function externalComposeSpec(string $name, string $expectedId): array
{
    $lines = [];
    $code = 1;
    $format = '{{.Id}}|{{.State.Status}}'
        . '|{{with index .Config.Labels "com.docker.compose.project"}}{{.}}{{end}}'
        . '|{{with index .Config.Labels "com.docker.compose.project.working_dir"}}{{.}}{{end}}'
        . '|{{with index .Config.Labels "com.docker.compose.project.config_files"}}{{.}}{{end}}';
    exec(
        PATH_ENV . ' ' . DOCKER . ' inspect --format ' . escapeshellarg($format)
        . ' ' . escapeshellarg($name) . ' 2>/dev/null',
        $lines,
        $code
    );
    if ($code !== 0) fail(404, '容器不存在: ' . $name);
    $parts = explode('|', rtrim((string) ($lines[0] ?? ''), "\r\n"));
    if (count($parts) !== 5) fail(422, '容器 Compose 标签格式无效,已取消更新');
    [$id, $status, $project, $workingDir, $configFiles] = $parts;
    $id = strtolower($id);
    if (!preg_match('/^[a-f0-9]{64}$/D', $id)) fail(503, '无法确认容器身份,已取消更新');
    if (!hash_equals($expectedId, $id)) fail(409, '容器已被外部替换,请刷新列表后重新确认更新');
    if (in_array(strtolower($status), ['paused', 'restarting', 'removing'], true)) {
        fail(409, '容器当前状态不适合更新,请先恢复运行或停止');
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/D', $project)) {
        fail(422, '容器不属于有效的外部 Compose 项目');
    }
    $workingDir = externalComposePath($workingDir, true);
    if (externalProjectIsManaged($project, [$workingDir])) {
        fail(409, 'compose.manager 项目请使用所属栈更新');
    }
    if ($configFiles === '' || strlen($configFiles) > 32768) {
        fail(422, 'Compose config_files 标签缺失或过长');
    }
    $files = explode(',', $configFiles);
    if (count($files) > 32) fail(422, 'Compose 配置文件数量超过上限');
    foreach ($files as &$file) {
        if ($file === '' || trim($file) !== $file || str_contains($file, ',')) {
            fail(422, 'Compose config_files 含无效路径');
        }
        $file = externalComposePath($file, false);
    }
    unset($file);
    if ($files === []) fail(422, 'Compose config_files 标签为空');
    return [
        'id' => $id,
        'name' => $name,
        'project' => $project,
        'workingDir' => $workingDir,
        'configFiles' => $files,
        'spec' => implode('|', [$id, $project, $workingDir, implode(',', $files)]),
    ];
}

/** 生成项目级独立进程组任务;pull 与 up 使用完全相同且保序的 Compose 参数。 */
function externalUpdateJobScript(array $job): string
{
    $jobId = (string) $job['id'];
    $fileArgs = '';
    $pathArgs = escapeshellarg((string) $job['workingDir']);
    foreach ($job['configFiles'] as $file) {
        $fileArgs .= ' -f ' . escapeshellarg((string) $file);
        $pathArgs .= ' ' . escapeshellarg((string) $file);
    }
    return '#!/bin/sh' . "\n"
        . 'set -u' . "\n"
        . 'opLock=' . escapeshellarg((string) $job['dir'] . '/op-lock') . "\n"
        . 'statusPath=' . escapeshellarg((string) $job['dir'] . '/status.json') . "\n"
        . 'resultPath=' . escapeshellarg((string) $job['dir'] . '/result.json') . "\n"
        . 'jobId=' . escapeshellarg($jobId) . "\n"
        . 'requestId=' . escapeshellarg((string) $job['requestId']) . "\n"
        . 'createdAt=' . (int) $job['createdAt'] . "\n"
        . 'name=' . escapeshellarg((string) $job['name']) . "\n"
        . 'project=' . escapeshellarg((string) $job['project']) . "\n"
        . 'workingDir=' . escapeshellarg((string) $job['workingDir']) . "\n"
        . 'expectedId=' . escapeshellarg((string) $job['expectedId']) . "\n"
        . 'expectedSpec=' . escapeshellarg((string) $job['spec']) . "\n"
        . 'specHash=' . escapeshellarg(hash('sha256', (string) $job['spec'])) . "\n"
        . 'dockerBin=' . escapeshellarg(DOCKER) . "\n"
        . 'setsidBin=' . escapeshellarg(DOCKER_UPDATE_SETSID) . "\n"
        . 'updateStatusScript=' . escapeshellarg(__DIR__ . '/update-status.php') . "\n"
        . 'auditLog=' . escapeshellarg(AUDIT_LOG) . "\n"
        . 'requestIp=' . escapeshellarg((string) $job['ip']) . "\n"
        . 'if [ "${1:-}" != "--start" ] || [ "${2:-}" != "$jobId" ]; then exit 64; fi' . "\n"
        . 'if [ "${3:-}" != "--grouped" ]; then exec "$setsidBin" /bin/sh "$0" --start "$jobId" --grouped; fi' . "\n"
        . 'cd ' . escapeshellarg((string) $job['dir']) . ' || exit 1' . "\n"
        . 'exec > update.log 2>&1' . "\n"
        . 'write_state() {' . "\n"
        . '  state="$1"; statRest="$(sed "s/.*) //" "/proc/$$/stat" 2>/dev/null)"' . "\n"
        . '  set -- $statRest; pgid="${3:-0}"; started="${20:-}"; tmp="$statusPath.tmp.$$"' . "\n"
        . '  printf \'%s\n\' "{\"id\":\"$jobId\",\"name\":\"$name\",\"project\":\"$project\",\"requestId\":\"$requestId\",\"expectedId\":\"$expectedId\",\"specHash\":\"$specHash\",\"createdAt\":$createdAt,\"updatedAt\":$(date +%s),\"pid\":$$,\"pgid\":${pgid:-0},\"startedTicks\":\"${started:-}\",\"state\":\"$state\"}" > "$tmp" && mv -f "$tmp" "$statusPath"' . "\n"
        . '}' . "\n"
        . 'write_result() {' . "\n"
        . '  ec="$1"; [ "$ec" -eq 0 ] && result=success || result=error; tmp="$resultPath.tmp.$$"' . "\n"
        . '  printf \'{"result":"%s","exit_code":%d,"operation":"externalupdate","timestamp":"%s"}\' "$result" "$ec" "$(date +%Y-%m-%dT%H:%M:%S%z)" > "$tmp" && mv -f "$tmp" "$resultPath"' . "\n"
        . '}' . "\n"
        . 'cleanup() {' . "\n"
        . '  if [ -f "$opLock" ] && [ "$(cat "$opLock")" = "$jobId" ]; then rm -f "$opLock"; fi' . "\n"
        . '}' . "\n"
        . 'trap cleanup EXIT' . "\n"
        . 'write_state running' . "\n"
        . 'assert_identity() {' . "\n"
        . '  line="$("$dockerBin" inspect --format \'{{.Id}}|{{.State.Status}}|{{with index .Config.Labels "com.docker.compose.project"}}{{.}}{{end}}|{{with index .Config.Labels "com.docker.compose.project.working_dir"}}{{.}}{{end}}|{{with index .Config.Labels "com.docker.compose.project.config_files"}}{{.}}{{end}}\' "$name" 2>/dev/null)" || return 1' . "\n"
        . '  currentStatus="$(printf \'%s\n\' "$line" | cut -d\'|\' -f2)"' . "\n"
        . '  currentSpec="$(printf \'%s\n\' "$line" | cut -d\'|\' -f1,3-)"' . "\n"
        . '  [ "$currentSpec" = "$expectedSpec" ] || { echo "!! 锚点 ID 或 Compose project/working_dir/config_files 已变化,已中止"; return 1; }' . "\n"
        . '  case "$(printf %s "$currentStatus" | tr "[:upper:]" "[:lower:]")" in paused|restarting|removing) echo "!! 容器状态已变化,已中止"; return 1;; esac' . "\n"
        . '  /usr/bin/php -r \'$paths = array_slice($argv, 1); foreach ($paths as $index => $path) { $real = realpath($path); if (!is_string($real) || $real !== $path || ($index === 0 ? !is_dir($real) : !is_file($real))) exit(1); }\' -- ' . $pathArgs . ' || { echo "!! Compose 路径已变化或失效,已中止"; return 1; }' . "\n"
        . '}' . "\n"
        . 'run_compose() {' . "\n"
        . '  "$dockerBin" compose --project-name "$project" --project-directory "$workingDir"' . $fileArgs . ' "$@"' . "\n"
        . '}' . "\n"
        . 'ec=0' . "\n"
        . 'if ! assert_identity; then ec=75; fi' . "\n"
        . 'echo "[$(date +%H:%M:%S)] 更新外部 Compose 项目 $project(锚点 $name)"' . "\n"
        . 'if [ "$ec" -eq 0 ]; then echo; echo "== Compose pull =="; run_compose pull || ec=$?; fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then assert_identity || ec=75; fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then echo; echo "== Compose up =="; run_compose up -d --build --force-recreate || ec=$?; fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then echo; echo "== 更新完成,正在刷新更新徽章 =="; '
        . '/usr/bin/php "$updateStatusScript" --compose ' . escapeshellarg((string) $job['project']) . ' ' . $pathArgs . ' >/dev/null 2>&1; fi' . "\n"
        . 'case "$ec" in \'\'|*[!0-9]*) ec=1;; esac' . "\n"
        . 'write_result "$ec"' . "\n"
        . '[ "$ec" -eq 0 ] && write_state succeeded || write_state failed' . "\n"
        . 'printf \'[%s] action=externalupdate stack=%s result=%s ip=%s\n\' "$(date +%Y-%m-%dT%H:%M:%S%z)" "$project" "$([ "$ec" -eq 0 ] && echo ok || echo fail)" "$requestIp" >> "$auditLog"' . "\n"
        . 'exit "$ec"' . "\n";
}

function runExternalUpdateAsync(array $spec, string $requestId): array
{
    if (!is_executable(DOCKER_UPDATE_SETSID)) fail(503, '宿主缺少 setsid,无法安全跟踪更新任务');
    $project = (string) $spec['project'];
    $name = (string) $spec['name'];
    $dir = EXTERNAL_UPDATE_DIR . '/' . $project;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fail(500, '外部更新工作目录创建失败');
    @chmod($dir, 0700);
    $existing = readExternalUpdateStatus($project);
    if ($existing['running'] === true) {
        $raw = readJsonFile($dir . '/status.json');
        if (is_string($existing['requestId'] ?? null) && hash_equals($requestId, $existing['requestId'])
            && ($raw['name'] ?? null) === $name
            && strtolower((string) ($raw['expectedId'] ?? '')) === $spec['id']) {
            return ['async' => true, 'jobId' => $existing['jobId'], 'requestId' => $requestId, 'duplicate' => true];
        }
        audit('externalupdate', $project, 'fail');
        fail(409, '该 Compose 项目有更新正在进行中,同项目容器不能并发更新');
    }

    $jobId = bin2hex(random_bytes(16));
    $queued = false;
    $opOwned = false;
    $lockFile = $dir . '/op-lock';
    register_shutdown_function(static function () use (&$queued, &$opOwned, $lockFile, $jobId): void {
        if (!$queued && $opOwned && trim((string) @file_get_contents($lockFile)) === $jobId) @unlink($lockFile);
    });
    $lock = @fopen($lockFile, 'x');
    if ($lock === false) {
        audit('externalupdate', $project, 'fail');
        fail(409, '该 Compose 项目有更新正在进行中,同项目容器不能并发更新');
    }
    if (@fwrite($lock, $jobId) === false) {
        @fclose($lock);
        @unlink($lockFile);
        fail(500, '外部更新项目锁写入失败');
    }
    fclose($lock);
    $opOwned = true;
    $createdAt = time();
    $job = [
        'id' => $jobId,
        'name' => $name,
        'project' => $project,
        'dir' => $dir,
        'requestId' => $requestId,
        'createdAt' => $createdAt,
        'workingDir' => $spec['workingDir'],
        'configFiles' => $spec['configFiles'],
        'expectedId' => $spec['id'],
        'spec' => $spec['spec'],
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
    ];
    try {
        @unlink($dir . '/result.json');
        writePluginJson($dir . '/status.json', [
            'id' => $jobId,
            'name' => $name,
            'project' => $project,
            'requestId' => $requestId,
            'expectedId' => $spec['id'],
            'specHash' => hash('sha256', (string) $spec['spec']),
            'createdAt' => $createdAt,
            'updatedAt' => $createdAt,
            'pid' => 0,
            'pgid' => 0,
            'startedTicks' => '',
            'state' => 'queued',
        ]);
        $script = $dir . '/job.sh';
        if (@file_put_contents($script, externalUpdateJobScript($job), LOCK_EX) === false) {
            throw new RuntimeException('外部更新任务脚本写入失败');
        }
        @chmod($script, 0700);
        @file_put_contents($dir . '/update.log', '', LOCK_EX);
    } catch (Throwable $e) {
        fail(500, $e->getMessage());
    }

    $spawnOutput = [];
    $spawnCode = 1;
    exec(PATH_ENV . ' nohup /bin/sh ' . escapeshellarg($script)
        . ' --start ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 < /dev/null & echo $!',
        $spawnOutput, $spawnCode);
    usleep(150000);
    $launchPid = (int) end($spawnOutput);
    $latest = readJsonFile($dir . '/status.json');
    $latestState = is_array($latest) ? (string) ($latest['state'] ?? '') : '';
    $started = in_array($latestState, ['running', 'succeeded', 'failed'], true);
    $launcherAlive = dockerUpdatePidAlive($launchPid);
    if ($spawnCode !== 0 || (!$started && !$launcherAlive)) {
        $error = ['result' => 'error', 'exit_code' => 1, 'operation' => 'externalupdate', 'timestamp' => date('c')];
        try {
            writePluginJson($dir . '/result.json', $error);
            if (is_array($latest)) {
                $latest['state'] = 'failed';
                $latest['updatedAt'] = time();
                $latest['message'] = '外部更新后台 worker 启动失败,未执行 pull 或项目重建';
                writePluginJson($dir . '/status.json', $latest);
            }
        } catch (Throwable) {
            // 启动失败仍以释放项目锁和返回明确错误为主。
        }
        if (trim((string) @file_get_contents($lockFile)) === $jobId) @unlink($lockFile);
        $queued = true;
        audit('externalupdate', $project, 'fail');
        fail(500, '外部更新后台 worker 启动失败,未执行更新');
    }
    $queued = true;
    audit('externalupdate', $project, 'queued');
    return ['async' => true, 'jobId' => $jobId, 'requestId' => $requestId];
}

function startExternalUpdate(array $body): array
{
    foreach (array_keys($body) as $key) {
        if (!in_array($key, ['action', 'name', 'expectedId', 'requestId'], true)) {
            fail(400, 'externalupdate 只接受 action/name/expectedId/requestId,路径与命令由服务端实时读取');
        }
    }
    $name = $body['name'] ?? null;
    if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name)) {
        fail(400, '非法容器名');
    }
    $expectedId = dockerUpdateExpectedId($body['expectedId'] ?? null);
    if ($expectedId === null) fail(400, 'externalupdate 必须提供 expectedId');
    if (!isset($body['requestId']) || $body['requestId'] === '') fail(400, 'externalupdate 必须提供 requestId');
    $requestId = dockerUpdateRequestId($body['requestId']);

    $existing = externalUpdateFindByRequestId($requestId);
    if ($existing !== null) {
        $raw = $existing['raw'];
        $recordedExpected = strtolower((string) ($raw['expectedId'] ?? ''));
        if (($raw['name'] ?? null) !== $name || $recordedExpected === ''
            || !hash_equals($expectedId, $recordedExpected)) {
            fail(409, 'requestId 已用于另一个锚点容器或容器身份,不能重复提交');
        }
        return [
            'async' => true,
            'jobId' => $existing['status']['jobId'],
            'requestId' => $existing['status']['requestId'],
            'duplicate' => true,
        ];
    }
    $spec = externalComposeSpec($name, $expectedId);
    return runExternalUpdateAsync($spec, $requestId);
}

/** 响应字段与 dockerlog 一致；requestId 恢复自身，project 在 409 后附着且不依赖锚点存活。 */
function externalUpdateLog(mixed $rawRequestId, mixed $rawName = null, mixed $rawProject = null): array
{
    if (is_string($rawRequestId) && $rawRequestId !== '') {
        $requestId = dockerUpdateRequestId($rawRequestId);
        $existing = externalUpdateFindByRequestId($requestId);
    } else {
        if (is_string($rawProject) && $rawProject !== '') {
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/D', $rawProject)) {
                fail(400, '非法 Compose 项目名');
            }
            $project = $rawProject;
            if (externalProjectIsManaged($project)) fail(409, 'compose.manager 项目请使用所属栈日志');
        } elseif (is_string($rawName) && $rawName !== '') {
            // 兼容只带 name 的旧客户端；新客户端优先 project，避免重建期间锚点消失。
            $project = externalUpdateProjectForContainer($rawName);
        } else {
            fail(400, '必须提供 requestId 或 project');
        }
        $existing = [
            'project' => $project,
            'dir' => EXTERNAL_UPDATE_DIR . '/' . $project,
            'raw' => readJsonFile(EXTERNAL_UPDATE_DIR . '/' . $project . '/status.json'),
            'status' => readExternalUpdateStatus($project),
        ];
    }
    if ($existing === null || !is_array($existing['raw'])) fail(404, '无外部 Compose 更新记录');
    $status = $existing['status'];
    return [
        'jobId' => $status['jobId'],
        'requestId' => $status['requestId'],
        'state' => $status['state'],
        'message' => $status['message'],
        'log' => tailFile($existing['dir'] . '/update.log'),
        'running' => $status['running'],
        'result' => $status['result'],
    ];
}

// ==================== 普通 Docker 容器参数编辑 ====================

function dockerEditName(mixed $name): string
{
    $name = is_string($name) ? $name : '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name)) {
        fail(400, '非法容器名');
    }
    return $name;
}

/** 服务端实时确认普通 Docker 容器;Compose 成员与暂停/重建中状态 fail closed。 */
function dockerEditContainer(string $name): array
{
    $lines = [];
    $code = 1;
    exec(
        PATH_ENV . ' ' . DOCKER . ' inspect --format '
        . escapeshellarg('{{.Id}}|{{.State.Running}}|{{.State.Status}}|{{with index .Config.Labels "com.docker.compose.project"}}{{.}}{{end}}')
        . ' ' . escapeshellarg($name) . ' 2>/dev/null',
        $lines,
        $code
    );
    if ($code !== 0) {
        fail(404, '容器不存在: ' . $name);
    }
    [$id, $running, $status, $project] = array_pad(explode('|', trim((string) ($lines[0] ?? '')), 4), 4, '');
    if ($project !== '') {
        fail(403, '该容器由 Compose 管理,请在对应栈中编辑');
    }
    if (in_array(strtolower($status), ['paused', 'restarting', 'removing'], true)) {
        fail(409, '容器当前状态不适合编辑,请先恢复运行或停止');
    }
    return ['id' => $id, 'running' => $running === 'true', 'status' => $status];
}

function dockerEditTemplate(string $name): array
{
    $path = findUserTemplate($name);
    if ($path === null || !is_file($path)) {
        fail(422, '该容器没有 Unraid 用户模板,不支持参数编辑');
    }
    $raw = (string) @file_get_contents($path);
    if ($raw === '' || strlen($raw) > CA_TEMPLATE_MAX_BYTES) {
        fail(422, '用户模板为空或超过大小上限');
    }
    $dom = @simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET);
    if (!$dom instanceof SimpleXMLElement || $dom->getName() !== 'Container' || (string) $dom['version'] !== '2') {
        fail(422, '仅支持有效的 version=2 Unraid 用户模板');
    }
    if (trim((string) $dom->Name) !== $name || trim((string) $dom->Repository) === '') {
        fail(422, '用户模板 Name/Repository 与容器不匹配');
    }
    if (in_array(strtolower(trim((string) $dom->TailscaleEnabled)), ['true', '1', 'yes', 'on'], true)) {
        fail(422, 'Tailscale 容器暂不支持在 App 内编辑,请使用 Unraid WebGUI');
    }
    $revision = hash('sha256', $raw);
    return ['path' => $path, 'raw' => $raw, 'dom' => $dom, 'revision' => $revision];
}

function dockerEditBool(string $raw): bool
{
    return in_array(strtolower(trim($raw)), ['true', '1', 'yes', 'on'], true);
}

function dockerEditActiveJobId(string $name): ?string
{
    $path = dockerContainerLockPath($name);
    $owner = trim((string) @file_get_contents($path));
    if (!preg_match('/^[a-f0-9]{32}$/D', $owner)) return null;
    $job = readJsonFile(DOCKER_EDIT_DIR . '/' . $owner . '.json');
    if (is_array($job) && ($job['id'] ?? null) === $owner && ($job['running'] ?? false) !== true) {
        releaseDockerContainerLock($path, $owner);
        return null;
    }
    return $owner;
}

function dockerEditData(string $name): array
{
    $container = dockerEditContainer($name);
    $template = dockerEditTemplate($name);
    $dom = $template['dom'];
    $configs = [];
    $index = 0;
    foreach ($dom->Config as $node) {
        $attrs = $node->attributes();
        $attr = static fn(string $key): string => (string) ($attrs[$key] ?? '');
        $default = $attr('Default');
        $raw = (string) $node;
        $value = $raw !== '' ? $raw : $default;
        $mask = dockerEditBool($attr('Mask'));
        $type = $attr('Type');
        $configs[] = [
            'id' => 'c' . $index,
            'target' => $attr('Target'),
            'name' => $attr('Name'),
            'default' => $mask ? '' : $default,
            'value' => $mask ? '' : $value,
            'hasValue' => $value !== '',
            'type' => $type,
            'mode' => $attr('Mode'),
            'description' => $attr('Description'),
            'display' => !in_array(strtolower($attr('Display')), ['never', 'hidden', 'hide', 'false', '0', 'no', 'off'], true),
            'required' => dockerEditBool($attr('Required')),
            'mask' => $mask,
            'editable' => in_array(strtolower($type), ['variable', 'port', 'path', 'device', 'label'], true),
        ];
        $index++;
    }
    return [
        'name' => $name,
        'revision' => $template['revision'],
        'repository' => (string) $dom->Repository,
        'network' => (string) $dom->Network,
        'webUI' => (string) $dom->WebUI,
        'privileged' => dockerEditBool((string) $dom->Privileged),
        'shell' => (string) $dom->Shell,
        'extraParams' => (string) $dom->ExtraParams,
        'postArgs' => (string) $dom->PostArgs,
        'cpuSet' => (string) $dom->CPUset,
        'myIP' => (string) $dom->MyIP,
        'myMAC' => (string) $dom->MyMAC,
        'extraNetworks' => (string) $dom->ExtraNetworks,
        'memory' => (string) $dom->Memory,
        'running' => $container['running'],
        'selfManaged' => $name === 'unraid-mobile',
        'activeJobId' => dockerEditActiveJobId($name),
        'configs' => $configs,
    ];
}

function dockerEditText(mixed $value, string $label, int $max = 65536): string
{
    if (!is_string($value) || strlen($value) > $max || str_contains($value, "\0")) {
        fail(400, $label . '必须是有效字符串且长度不超过 ' . $max);
    }
    return $value;
}

/** 只改白名单节点;其余 XML 节点、属性与顺序由 SimpleXML 原样保留。 */
function dockerEditApply(array $body, array $template): array
{
    $name = dockerEditName($body['name'] ?? null);
    $revision = $body['revision'] ?? null;
    if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)
        || !hash_equals($template['revision'], $revision)) {
        fail(409, '模板已被其他页面修改,请重新加载后再编辑');
    }
    $dom = $template['dom'];
    $fields = [
        'repository' => 'Repository', 'network' => 'Network', 'webUI' => 'WebUI',
        'shell' => 'Shell', 'extraParams' => 'ExtraParams', 'postArgs' => 'PostArgs',
        'cpuSet' => 'CPUset', 'myIP' => 'MyIP', 'myMAC' => 'MyMAC',
        'extraNetworks' => 'ExtraNetworks', 'memory' => 'Memory',
    ];
    foreach ($fields as $key => $nodeName) {
        if (!array_key_exists($key, $body)) {
            fail(400, '缺少基础字段: ' . $key);
        }
        $value = dockerEditText($body[$key], $key, $key === 'extraParams' || $key === 'postArgs' ? 65536 : 4096);
        if ($key === 'repository' || $key === 'network') {
            $value = trim($value);
            if ($value === '' || preg_match('/\s/', $value)) {
                fail(422, ($key === 'repository' ? '镜像仓库' : '网络模式') . '不能为空或包含空白字符');
            }
        }
        $dom->{$nodeName} = $value;
    }
    if (!array_key_exists('privileged', $body) || !is_bool($body['privileged'])) {
        fail(400, 'privileged 必须是布尔值');
    }
    $dom->Privileged = $body['privileged'] ? 'true' : 'false';

    $configs = $body['configs'] ?? null;
    if (!is_array($configs) || !array_is_list($configs) || count($configs) > 512) {
        fail(400, 'configs 必须是最多 512 项的数组');
    }
    $existing = [];
    $index = 0;
    foreach ($dom->Config as $node) {
        $existing['c' . $index] = $node;
        $index++;
    }
    $seen = [];
    $kept = [];
    $secrets = [];
    // 被删除的掩码项也可能出现在原容器恢复命令中,必须在处理删除前收集。
    foreach ($existing as $node) {
        if (!dockerEditBool((string) $node['Mask'])) continue;
        foreach ([(string) $node, (string) $node['Default']] as $secret) {
            if ($secret !== '') $secrets[] = $secret;
        }
    }
    foreach ($configs as $input) {
        if (!is_array($input)) {
            fail(400, 'Config 项必须是对象');
        }
        $id = $input['id'] ?? '';
        if (!is_string($id) || ($id !== '' && !isset($existing[$id]))
            || ($id !== '' && isset($seen[$id]))) {
            fail(400, 'Config ID 无效或重复');
        }
        if ($id === '' && !preg_match('/^[a-f0-9]{8}$/D', (string) ($input['clientId'] ?? ''))) {
            // clientId 只用于新行追踪;服务端最终仍以本次 XML 顺序生成稳定 ID。
            if (!isset($input['clientId']) || $input['clientId'] !== '') {
                fail(400, '新 Config 的 clientId 无效');
            }
        }
        if ($id !== '') {
            $seen[$id] = true;
        }
        $isNew = $id === '';
        if ($isNew) {
            $node = $dom->addChild('Config');
            $type = strtolower((string) ($input['type'] ?? ''));
            $canonical = ['variable' => 'Variable', 'port' => 'Port', 'path' => 'Path', 'device' => 'Device', 'label' => 'Label'][$type] ?? null;
            if ($canonical === null) {
                fail(422, '新增 Config 仅支持 Variable/Port/Path/Device/Label');
            }
            $node['Type'] = $canonical;
            $node['Default'] = '';
            $node['Description'] = '';
            $node['Display'] = 'always';
            $node['Required'] = 'false';
            $node['Mask'] = !empty($input['mask']) && $canonical === 'Variable' ? 'true' : 'false';
        } else {
            $node = $existing[$id];
            $originalType = (string) $node['Type'];
            if (!in_array(strtolower($originalType), ['variable', 'port', 'path', 'device', 'label'], true)) {
                $unchanged = ($input['target'] ?? (string) $node['Target']) === (string) $node['Target']
                    && ($input['name'] ?? (string) $node['Name']) === (string) $node['Name']
                    && ($input['mode'] ?? (string) $node['Mode']) === (string) $node['Mode']
                    && strtolower((string) ($input['type'] ?? '')) === strtolower($originalType);
                if (!$unchanged) {
                    fail(422, '该 Config 类型只支持保留或删除: ' . $originalType);
                }
                // 只读项完全未变时允许省略 value,尤其是客户端无法知道原值的掩码项。
                $kept[] = $node;
                continue;
            } elseif (strtolower((string) ($input['type'] ?? '')) !== strtolower($originalType)) {
                fail(422, '不允许改变已有 Config 的 Type');
            }
        }
        $kept[] = $node;
        $type = strtolower((string) $node['Type']);
        $mask = dockerEditBool((string) $node['Mask']);
        $required = dockerEditBool((string) $node['Required']);
        $originalRaw = (string) $node;
        $originalDefault = (string) $node['Default'];
        if ($mask) {
            foreach ([$originalRaw, $originalDefault] as $secret) {
                if ($secret !== '') {
                    $secrets[] = $secret;
                }
            }
        }
        $target = dockerEditText($input['target'] ?? '', 'Config Target', 4096);
        $configName = dockerEditText($input['name'] ?? '', 'Config Name', 4096);
        $mode = dockerEditText($input['mode'] ?? '', 'Config Mode', 128);
        if ($target === '' || preg_match('/[\x00-\x20\x7f]/', $target)) {
            fail(422, 'Config Target 不能为空或包含空白/控制字符');
        }
        if ($type === 'port') {
            if (!ctype_digit($target) || (int) $target < 1 || (int) $target > 65535) {
                fail(422, '容器端口必须是 1-65535 的数字');
            }
            $mode = strtolower($mode);
            if (!in_array($mode, ['tcp', 'udp'], true)) {
                fail(422, '端口协议只能是 tcp 或 udp');
            }
        } elseif ($type === 'path') {
            if (!str_starts_with($target, '/')) {
                fail(422, '容器路径必须是绝对路径');
            }
            $mode = strtolower($mode);
            if (!in_array($mode, ['rw', 'rw,slave', 'rw,shared', 'ro', 'ro,slave', 'ro,shared'], true)) {
                fail(422, '路径模式无效');
            }
        } elseif ($type === 'device' && !str_starts_with($target, '/dev/')) {
            fail(422, '容器设备路径必须以 /dev/ 开头');
        }
        $node['Target'] = $target;
        $node['Name'] = $configName;
        $node['Mode'] = $mode;

        $clearSecret = ($input['clearSecret'] ?? false) === true;
        if ($mask && !$isNew && !array_key_exists('value', $input) && !$clearSecret) {
            // 掩码项缺省 value = 保留原值,不用空字符串覆盖密码。
        } else {
            $value = $input['value'] ?? '';
            if ($value === null && $mask && !$clearSecret) {
                $value = null;
            } else {
                $value = dockerEditText($value, 'Config Value');
            }
            if ($clearSecret) {
                $node['Default'] = '';
                $node[0] = '';
            } elseif ($value !== null) {
                if ($type === 'port' && $value !== ''
                    && (!ctype_digit($value) || (int) $value < 1 || (int) $value > 65535)) {
                    fail(422, '宿主机端口必须是 1-65535 的数字');
                }
                if ($type === 'path' && $value !== '' && !str_starts_with($value, '/')) {
                    fail(422, '宿主机路径必须是绝对路径');
                }
                if ($type === 'device' && $value !== '' && !str_starts_with($value, '/dev/')) {
                    fail(422, '宿主设备路径必须以 /dev/ 开头');
                }
                $node[0] = $value;
                // xmlToVar 在节点为空时回退 Default;显式清空必须同步清 Default。
                if ($value === '') $node['Default'] = '';
                if ($mask && $value !== '') {
                    $secrets[] = $value;
                }
            }
        }
        $effective = (string) $node !== '' ? (string) $node : (string) $node['Default'];
        if ($required && trim($effective) === '') {
            fail(422, '必填 Config 不能为空: ' . ($configName !== '' ? $configName : $target));
        }
    }
    // 客户端提交完整列表:未出现的旧 ID 即明确删除;隐藏项也由客户端原样回传。
    foreach ($existing as $id => $node) {
        if (!isset($seen[$id])) {
            $domNode = dom_import_simplexml($node);
            $domNode?->parentNode?->removeChild($domNode);
        }
    }
    $xml = $dom->asXML();
    $check = is_string($xml) ? @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET) : false;
    if (!is_string($xml) || !$check instanceof SimpleXMLElement) {
        fail(422, '编辑后的模板 XML 校验失败');
    }
    return [
        'name' => $name,
        'templatePath' => $template['path'],
        'original' => $template['raw'],
        'candidate' => $xml,
        'candidateRevision' => hash('sha256', $xml),
        'secrets' => array_values(array_unique($secrets)),
    ];
}

function dockerEditAtomicWrite(string $path, string $xml, string $suffix, ?string $expectedRevision = null): void
{
    if ($expectedRevision !== null) {
        $current = @hash_file('sha256', $path);
        if (!is_string($current) || !hash_equals($expectedRevision, $current)) {
            throw new RuntimeException('模板在保存前已被其他页面修改,未覆盖外部修改');
        }
    }
    $tmp = $path . '.' . $suffix . '.tmp';
    if (@file_put_contents($tmp, $xml, LOCK_EX) === false) {
        throw new RuntimeException('模板临时文件写入失败,原模板未改动');
    }
    @chmod($tmp, 0644);
    $check = @simplexml_load_file($tmp, SimpleXMLElement::class, LIBXML_NONET);
    if (!$check instanceof SimpleXMLElement || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('模板原子替换失败,原模板未改动');
    }
}

function dockerEditJobScript(array $job): string
{
    $template = <<<'PHP'
<?php

declare(strict_types=1);

const EDIT_WORK_DIR = __WORK_DIR__;
const EDIT_DOCKER = __DOCKER__;
const EDIT_DOCKER_HELPER = __HELPER__;
const EDIT_AUDIT = __AUDIT__;
$job = __JOB__;
$logPath = EDIT_WORK_DIR . '/' . $job['id'] . '.log';
$statusPath = EDIT_WORK_DIR . '/' . $job['id'] . '.json';
$secrets = $job['secrets'];

function editJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('任务状态编码失败');
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('任务状态写入失败');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('任务状态原子替换失败');
    }
}

function editRedact(string $text, array $secrets): string
{
    foreach ($secrets as $secret) {
        if (!is_string($secret) || $secret === '') continue;
        $variants = [$secret, htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            addslashes($secret), escapeshellarg($secret)];
        $text = str_replace(array_unique($variants), '********', $text);
    }
    return $text;
}

function editLog(string $message): void
{
    global $logPath, $secrets;
    if ($message === '') return;
    @file_put_contents($logPath, editRedact($message, $secrets), FILE_APPEND | LOCK_EX);
    @chmod($logPath, 0600);
}

function editReleaseLock(string $path, string $owner): void
{
    if (trim((string) @file_get_contents($path)) === $owner) @unlink($path);
}

function editRun(string $command, ?string &$output = null): int
{
    $output = '';
    $proc = @popen($command . ' 2>&1', 'r');
    if ($proc === false) return 125;
    while (($line = fgets($proc)) !== false) {
        $output .= $line;
        editLog($line);
    }
    return pclose($proc);
}

function editState(string $name): ?array
{
    $lines = [];
    $code = 1;
    exec(EDIT_DOCKER . ' inspect --format '
        . escapeshellarg('{{.Id}}|{{.State.Running}}|{{.State.Status}}|{{with index .Config.Labels "com.docker.compose.project"}}{{.}}{{end}}')
        . ' ' . escapeshellarg($name) . ' 2>/dev/null', $lines, $code);
    if ($code !== 0) return null;
    [$id, $running, $status, $project] = array_pad(explode('|', trim((string) ($lines[0] ?? '')), 4), 4, '');
    return ['id' => $id, 'running' => $running === 'true', 'status' => $status, 'project' => $project];
}

function editCommand(string $xmlPath, bool $start): string
{
    [$command, $name, $repository] = xmlToCommand($xmlPath);
    if (!is_string($command) || !str_contains($command, '/docker create ')) {
        throw new RuntimeException('官方 xmlToCommand 未生成有效的 docker create 命令');
    }
    return $start ? str_replace('/docker create ', '/docker run -d ', $command) : $command;
}

function editTemplateXml(string $path, ?string $expectedRevision = null): SimpleXMLElement
{
    if ($expectedRevision !== null) {
        $current = @hash_file('sha256', $path);
        if (!is_string($current) || !hash_equals($expectedRevision, $current)) {
            throw new RuntimeException('模板在任务期间已被其他页面修改,为避免混用配置已中止');
        }
    }
    $dom = @simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
    if (!$dom instanceof SimpleXMLElement) throw new RuntimeException('任务模板快照读取失败');
    return $dom;
}

function editAssertIdentity(array $job, array $expected, ?bool $running = null): array
{
    $current = editState($job['name']);
    if ($current === null || !hash_equals($expected['id'], $current['id'])) {
        throw new RuntimeException('容器已被外部替换或删除,已中止且不会操作同名新容器');
    }
    if ($current['project'] !== '') throw new RuntimeException('容器已变为 Compose 管理,已中止');
    if (in_array(strtolower($current['status']), ['paused', 'restarting', 'removing'], true)) {
        throw new RuntimeException('容器当前状态已变化,不适合继续重建');
    }
    if ($running !== null && $current['running'] !== $running) {
        throw new RuntimeException('容器运行状态已被外部修改,已中止');
    }
    return $current;
}

function editApplyNetworks(string $name, SimpleXMLElement $dom, bool $running): void
{
    $extraNetworks = trim((string) $dom->ExtraNetworks);
    $primaryNetwork = trim((string) $dom->Network);
    if ($extraNetworks !== '') {
        if (!function_exists('connectExtraNetworks')) {
            throw new RuntimeException('当前 Unraid 缺少附加网络连接助手');
        }
        if (!connectExtraNetworks($name, $extraNetworks, $primaryNetwork)) {
            throw new RuntimeException('附加网络连接失败');
        }
    }
    if ($running && function_exists('addRoute') && addRoute($name) === false) {
        throw new RuntimeException('容器自定义网络路由恢复失败');
    }
}

function editRestoreTemplate(array $job): bool
{
    $current = @hash_file('sha256', $job['templatePath']);
    if (!is_string($current) || !hash_equals($job['candidateRevision'], $current)) {
        editLog("[unraid-mobile] 模板在任务期间被其他页面修改,不覆盖该外部修改\n");
        return false;
    }
    $tmp = $job['templatePath'] . '.rollback.' . $job['id'] . '.tmp';
    if (@file_put_contents($tmp, $job['original'], LOCK_EX) === false) return false;
    @chmod($tmp, 0644);
    return @rename($tmp, $job['templatePath']);
}

$error = null;
$success = false;
$recovered = null;
$templateRestored = null;
$oldRemoved = false;
$originalRunning = false;
$originalCommand = null;
$old = null;
$candidateId = null;
$finished = false;
register_shutdown_function(static function () use (&$finished, &$error, &$recovered, &$templateRestored, $job, $statusPath): void {
    if ($finished) return;
    $result = ['success' => false, 'name' => $job['name'], 'finishedAt' => date('c'),
        'error' => $error ?? '任务异常终止', 'recovered' => $recovered, 'templateRestored' => $templateRestored];
    try {
        editJson($statusPath, ['id' => $job['id'], 'name' => $job['name'], 'running' => false,
            'pid' => getmypid(), 'result' => $result, 'createdAt' => $job['createdAt'],
            'finishedAt' => $result['finishedAt']]);
    } catch (Throwable $writeError) {
        editLog('[unraid-mobile] 终态写入失败,保留任务锁与恢复材料: ' . $writeError->getMessage() . "\n");
        return;
    }
    editReleaseLock(EDIT_WORK_DIR . '/' . $job['name'] . '.lock', $job['id']);
});

ignore_user_abort(true);
set_time_limit(0);
editJson($statusPath, ['id' => $job['id'], 'name' => $job['name'], 'createdAt' => $job['createdAt'],
    'running' => true, 'pid' => getmypid(), 'result' => null]);
editLog('[' . date('c') . '] 开始编辑普通容器: ' . $job['name'] . "\n");
try {
    // 命令、镜像与网络全部从任务私有不可变快照读取,绝不混用任务期间的外部模板修改。
    $candidateDom = editTemplateXml($job['candidatePath'], $job['candidateRevision']);
    editTemplateXml($job['templatePath'], $job['candidateRevision']);
    $originalDom = editTemplateXml($job['originalPath']);
    $old = editState($job['name']);
    if ($old === null || !hash_equals($job['expectedId'], $old['id'])) {
        throw new RuntimeException('容器已在提交后被外部替换或删除,未执行重建');
    }
    $old = editAssertIdentity($job, $old);
    $originalRunning = $old['running'];

    $docroot = '/usr/local/emhttp';
    require_once $docroot . '/plugins/dynamix.docker.manager/include/DockerClient.php';
    require_once EDIT_DOCKER_HELPER;
    require_once $docroot . '/webGui/include/Helpers.php';
    extract(parse_plugin_cfg('dynamix', true));
    $var = parse_ini_file($docroot . '/state/var.ini');
    $custom = DockerUtil::custom();
    $subnet = DockerUtil::network($custom);
    $primaryNetwork = trim((string) $candidateDom->Network);
    if (!array_key_exists($primaryNetwork, $subnet)
        && !preg_match('/^container:[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $primaryNetwork)) {
        throw new RuntimeException('网络模式不存在或无效: ' . $primaryNetwork . ';旧容器未改动');
    }

    // 候选与原命令都在删除旧容器前生成;失败可继续使用旧镜像恢复。
    $candidateCommand = editCommand($job['candidatePath'], $originalRunning);
    $originalCommand = editCommand($job['originalPath'], $originalRunning);
    $repository = trim((string) $candidateDom->Repository);
    $imageCode = 1;
    exec(EDIT_DOCKER . ' image inspect ' . escapeshellarg($repository) . ' >/dev/null 2>&1', $unused, $imageCode);
    if ($imageCode !== 0) {
        editLog("[unraid-mobile] 本地缺少镜像,开始拉取(已有镜像时不会 pull)\n");
        if (editRun(EDIT_DOCKER . ' pull ' . escapeshellarg($repository)) !== 0) {
            throw new RuntimeException('镜像拉取失败,旧容器未改动');
        }
    } else {
        editLog("[unraid-mobile] 使用本地现有镜像,不检查或拉取更新\n");
    }

    // 拉取可能耗时很长;停止前重新确认模板、容器身份、Compose 标签和用户运行状态。
    editTemplateXml($job['candidatePath'], $job['candidateRevision']);
    editTemplateXml($job['templatePath'], $job['candidateRevision']);
    editAssertIdentity($job, $old, $originalRunning);
    if ($originalRunning) {
        editLog("[unraid-mobile] 停止原容器\n");
        if (editRun(EDIT_DOCKER . ' stop --time 10 ' . escapeshellarg($job['name'])) !== 0) {
            throw new RuntimeException('停止原容器失败,未删除容器');
        }
    }
    // stop 后、rm 前再核对一次,绝不删除外部替换的同名容器。
    editAssertIdentity($job, $old, false);
    editTemplateXml($job['templatePath'], $job['candidateRevision']);
    editLog("[unraid-mobile] 移除原容器(不删除镜像或卷)\n");
    $rmCode = editRun(EDIT_DOCKER . ' rm -f ' . escapeshellarg($job['name']));
    $afterRm = editState($job['name']);
    // CLI 可能已删除旧容器但返回错误;以实际 inspect 决定后续恢复,不只看退出码。
    $oldRemoved = $afterRm === null || $afterRm['id'] !== $old['id'];
    if ($rmCode !== 0) throw new RuntimeException('移除原容器命令返回失败');
    if (!$oldRemoved) throw new RuntimeException('移除命令完成后旧容器仍存在,未执行重建');
    if ($afterRm !== null) throw new RuntimeException('删除期间出现外部同名容器,恢复流程不会删除它');
    editLog("[unraid-mobile] 执行官方 xmlToCommand 生成的重建命令\n");
    $createOutput = '';
    $createCode = editRun($candidateCommand, $createOutput);
    $createdId = null;
    if ($createCode === 0 && preg_match('/\b[a-f0-9]{64}\b/', $createOutput, $createdMatch)) {
        $createdId = $createdMatch[0];
    }
    $new = editState($job['name']);
    // 只有本次命令返回的完整容器 ID 才能证明同名容器归属,名称冲突时绝不清理外部容器。
    if ($createdId !== null && $new !== null && $new['id'] === $createdId) $candidateId = $createdId;
    if ($createCode !== 0 || $createdId === null || $new === null || $new['id'] !== $createdId
        || $new['project'] !== '' || $new['running'] !== $originalRunning) {
        throw new RuntimeException('新容器创建、容器归属或运行状态校验失败');
    }
    editApplyNetworks($job['name'], $candidateDom, $originalRunning);
    editTemplateXml($job['templatePath'], $job['candidateRevision']);
    $success = true;
    editLog("[unraid-mobile] 参数已应用,容器重建成功\n");
} catch (Throwable $e) {
    $error = editRedact($e->getMessage(), $secrets);
    editLog('[unraid-mobile] 编辑失败: ' . $error . "\n");
    $templateRestored = editRestoreTemplate($job);
    try {
        $restored = editState($job['name']);
        if ($oldRemoved) {
            if ($restored !== null) {
                if ($old !== null && $restored['id'] === $old['id']) {
                    $oldRemoved = false;
                } elseif ($candidateId !== null && $restored['id'] === $candidateId) {
                    editLog("[unraid-mobile] 清理本次任务创建的候选容器\n");
                    if (editRun(EDIT_DOCKER . ' rm -f ' . escapeshellarg($job['name'])) !== 0) {
                        throw new RuntimeException('候选容器清理失败,不覆盖同名容器');
                    }
                    $restored = null;
                } else {
                    throw new RuntimeException('检测到无法确认归属的同名容器,恢复流程不会删除它');
                }
            }
            if ($restored === null && is_string($originalCommand)) {
                editLog("[unraid-mobile] 尝试使用原模板恢复原容器\n");
                $restoreCode = editRun($originalCommand);
                $restored = editState($job['name']);
                if ($restoreCode !== 0 || $restored === null || $restored['running'] !== $originalRunning) {
                    throw new RuntimeException('原容器命令恢复或运行状态校验失败');
                }
                editApplyNetworks($job['name'], $originalDom, $originalRunning);
                $recovered = true;
            }
        }
        if (!$oldRemoved && $restored !== null && $old !== null && $restored['id'] === $old['id']) {
            if ($originalRunning && !$restored['running']) {
                // stop 成功但 rm 失败:旧容器仍在,只需重新启动,不要重复 create。
                $startCode = editRun(EDIT_DOCKER . ' start ' . escapeshellarg($job['name']));
                $restored = editState($job['name']);
                if ($startCode !== 0 || $restored === null || !$restored['running']) {
                    throw new RuntimeException('原容器重新启动失败');
                }
                editApplyNetworks($job['name'], $originalDom, true);
            }
            $recovered = $restored['running'] === $originalRunning;
        } elseif ($recovered !== true) {
            $recovered = false;
        }
    } catch (Throwable $recoveryError) {
        $recovered = false;
        editLog('[unraid-mobile] 恢复失败: ' . editRedact($recoveryError->getMessage(), $secrets) . "\n");
    }
}

$result = ['success' => $success, 'name' => $job['name'], 'finishedAt' => date('c'),
    'error' => $success ? null : $error, 'recovered' => $success ? null : $recovered,
    'templateRestored' => $success ? null : $templateRestored];
editJson($statusPath, ['id' => $job['id'], 'name' => $job['name'], 'createdAt' => $job['createdAt'],
    'running' => false, 'pid' => getmypid(), 'result' => $result, 'finishedAt' => $result['finishedAt']]);
$size = @filesize(EDIT_AUDIT);
if ($size !== false && $size > 1048576) @rename(EDIT_AUDIT, EDIT_AUDIT . '.old');
@file_put_contents(EDIT_AUDIT, sprintf('[%s] action=dockeredit stack=%s result=%s ip=%s\n',
    date('c'), $job['name'], $success ? 'ok' : 'fail', $job['ip']), FILE_APPEND | LOCK_EX);
editReleaseLock(EDIT_WORK_DIR . '/' . $job['name'] . '.lock', $job['id']);
@unlink($job['originalPath']);
@unlink($job['candidatePath']);
@unlink(__FILE__);
$finished = true;
PHP;
    return strtr($template, [
        '__WORK_DIR__' => var_export(DOCKER_EDIT_DIR, true),
        '__DOCKER__' => var_export(DOCKER, true),
        '__HELPER__' => var_export(DOCKER_EDIT_HELPER, true),
        '__AUDIT__' => var_export(AUDIT_LOG, true),
        '__JOB__' => var_export($job, true),
    ]);
}

function startDockerEdit(array $body): string
{
    $name = dockerEditName($body['name'] ?? null);
    $container = dockerEditContainer($name);
    if (!is_file(DOCKER_EDIT_HELPER)) {
        fail(503, 'Unraid Docker Helpers.php 不存在,无法使用官方模板重建');
    }
    if (readDockerUpdateStatus($name)['running'] === true) {
        fail(409, '该容器有镜像更新正在进行中,请等待完成');
    }
    ensureDockerEditDir();
    $id = bin2hex(random_bytes(16));
    // 已写终态但异常退出留下的锁可以安全回收;运行中的任务绝不抢占。
    $existingOwner = trim((string) @file_get_contents(dockerContainerLockPath($name)));
    if (preg_match('/^[a-f0-9]{32}$/D', $existingOwner)) {
        $existing = readJsonFile(DOCKER_EDIT_DIR . '/' . $existingOwner . '.json');
        if (is_array($existing) && ($existing['running'] ?? false) !== true) {
            releaseDockerContainerLock(dockerContainerLockPath($name), $existingOwner);
        }
    }
    $lockPath = acquireDockerContainerLock($name, $id);
    $queued = false;
    register_shutdown_function(static function () use (&$queued, $lockPath, $id): void {
        if (!$queued) releaseDockerContainerLock($lockPath, $id);
    });
    $templateWritten = false;
    $prepared = null;
    try {
        // 获取原子锁后再复核旧更新任务,兼容部署前已在运行的更新 worker。
        if (readDockerUpdateStatus($name)['running'] === true) {
            fail(409, '该容器有镜像更新正在进行中,请等待完成');
        }
        $template = dockerEditTemplate($name);
        $prepared = dockerEditApply($body, $template);
        $originalPath = DOCKER_EDIT_DIR . '/' . $id . '.original.xml';
        $candidatePath = DOCKER_EDIT_DIR . '/' . $id . '.candidate.xml';
        if (@file_put_contents($originalPath, $prepared['original'], LOCK_EX) === false
            || @file_put_contents($candidatePath, $prepared['candidate'], LOCK_EX) === false) {
            throw new RuntimeException('原模板或候选快照备份失败');
        }
        @chmod($originalPath, 0600);
        @chmod($candidatePath, 0600);
        $job = [
            'id' => $id, 'name' => $name, 'createdAt' => date('c'), 'expectedId' => $container['id'],
            'templatePath' => $prepared['templatePath'], 'originalPath' => $originalPath,
            'candidatePath' => $candidatePath, 'original' => $prepared['original'],
            'candidateRevision' => $prepared['candidateRevision'],
            'secrets' => $prepared['secrets'], 'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
        ];
        $script = DOCKER_EDIT_DIR . '/' . $id . '.php';
        if (@file_put_contents($script, dockerEditJobScript($job), LOCK_EX) === false) {
            throw new RuntimeException('参数编辑 worker 写入失败');
        }
        @chmod($script, 0700);
        writePluginJson(DOCKER_EDIT_DIR . '/' . $id . '.json', [
            'id' => $id, 'name' => $name, 'createdAt' => $job['createdAt'], 'running' => true, 'result' => null,
        ]);
        @file_put_contents(DOCKER_EDIT_DIR . '/' . $id . '.log', '', LOCK_EX);
        @chmod(DOCKER_EDIT_DIR . '/' . $id . '.log', 0600);
        // rename 前再次核对提交 revision;worker 只读 candidatePath,不再跟随线上模板变化。
        dockerEditAtomicWrite($prepared['templatePath'], $prepared['candidate'], $id, $template['revision']);
        $templateWritten = true;
        audit('dockeredit', $name, 'queued');
        $spawnOutput = [];
        $spawnCode = 1;
        exec(PATH_ENV . ' nohup /usr/bin/php ' . escapeshellarg($script)
            . ' > /dev/null 2>&1 < /dev/null & echo $!', $spawnOutput, $spawnCode);
        usleep(100000);
        $pid = (int) end($spawnOutput);
        $latest = readJsonFile(DOCKER_EDIT_DIR . '/' . $id . '.json');
        $alive = $pid > 0 && (!function_exists('posix_kill') || @posix_kill($pid, 0));
        if ($spawnCode !== 0 || (!is_array($latest['result'] ?? null) && !$alive)) {
            throw new RuntimeException('参数编辑后台 worker 启动失败');
        }
        $queued = true;
        return $id;
    } catch (Throwable $e) {
        if ($templateWritten && is_array($prepared)) {
            try {
                dockerEditAtomicWrite($prepared['templatePath'], $prepared['original'],
                    $id . '.startup-rollback', $prepared['candidateRevision']);
            } catch (Throwable) {
                // 不覆盖外部修改;保留快照和日志供排查。
            }
        }
        releaseDockerContainerLock($lockPath, $id);
        fail(500, $e->getMessage());
    }
}

function readDockerEditJob(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
    $job = readJsonFile(DOCKER_EDIT_DIR . '/' . $id . '.json');
    if (!is_array($job) || ($job['id'] ?? null) !== $id) return null;
    $running = ($job['running'] ?? false) === true;
    $pid = (int) ($job['pid'] ?? 0);
    // worker 被 kill 且 shutdown 未能执行时,不能让客户端永久轮询 running:true。
    if ($running && $pid > 0 && function_exists('posix_kill') && !@posix_kill($pid, 0)) {
        $running = false;
        $job['running'] = false;
        $job['finishedAt'] = date('c');
        $job['result'] = ['success' => false, 'name' => (string) ($job['name'] ?? ''),
            'finishedAt' => $job['finishedAt'], 'error' => '参数编辑进程异常终止,请根据日志检查模板与容器状态',
            'recovered' => null, 'templateRestored' => null];
        writePluginJson(DOCKER_EDIT_DIR . '/' . $id . '.json', $job);
    }
    if (!$running) {
        releaseDockerContainerLock(dockerContainerLockPath((string) ($job['name'] ?? '')), $id);
    }
    return [
        'id' => $id,
        'name' => (string) ($job['name'] ?? ''),
        'running' => $running,
        'log' => tailFile(DOCKER_EDIT_DIR . '/' . $id . '.log'),
        'result' => is_array($job['result'] ?? null) ? $job['result'] : null,
    ];
}

// ==================== Unraid 官方插件管理 ====================

function pluginId(mixed $name): string
{
    $name = (string) $name;
    if (
        !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.plg$/D', $name)
        || basename($name) !== $name
        || str_contains($name, '..')
    ) {
        fail(400, '非法插件名');
    }
    return $name;
}

function pluginInstalled(string $id): bool
{
    return is_file(PLUGIN_INSTALLED_DIR . '/' . $id);
}

function operatingSystemPlugin(string $id): bool
{
    return in_array(strtolower($id), ['unraidserver.plg', 'unraidserver-.plg'], true);
}

function pluginManaged(string $id): bool
{
    return strtolower($id) !== 'compose.manager.plg' && !operatingSystemPlugin($id);
}

function pluginXml(string $path): ?SimpleXMLElement
{
    $xml = @file_get_contents($path);
    if ($xml === false || trim($xml) === '') {
        return null;
    }
    $dom = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    return $dom instanceof SimpleXMLElement ? $dom : null;
}

function pluginCache(): array
{
    $cache = readJsonFile(PLUGIN_WORK_DIR . '/cache.json') ?? [];
    return [
        'checkedAt' => is_string($cache['checkedAt'] ?? null) ? $cache['checkedAt'] : null,
        'plugins' => is_array($cache['plugins'] ?? null) ? $cache['plugins'] : [],
    ];
}

function writePluginJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('插件状态编码失败');
    }
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('插件状态写入失败');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('插件状态原子替换失败');
    }
}

function readPluginJob(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
        return null;
    }
    $job = readJsonFile(PLUGIN_WORK_DIR . '/' . $id . '.json');
    if (!is_array($job) || ($job['id'] ?? null) !== $id) {
        return null;
    }
    return [
        'id' => $id,
        'operation' => (string) ($job['operation'] ?? ''),
        'state' => (string) ($job['state'] ?? 'queued'),
        'current' => (int) ($job['current'] ?? 0),
        'total' => (int) ($job['total'] ?? 0),
        'log' => tailFile(PLUGIN_WORK_DIR . '/' . $id . '.log'),
        'results' => is_array($job['results'] ?? null) ? array_values($job['results']) : [],
        'error' => is_string($job['error'] ?? null) ? $job['error'] : null,
    ];
}

function activePluginJob(): ?array
{
    $id = trim((string) @file_get_contents(PLUGIN_WORK_DIR . '/active.lock'));
    return $id !== '' ? readPluginJob($id) : null;
}

function listPlugins(): array
{
    $cache = pluginCache();
    $active = activePluginJob();
    $checking = $active !== null
        && $active['operation'] === 'plugincheck'
        && in_array($active['state'], ['queued', 'running'], true);
    $completed = [];
    if ($checking) {
        foreach ($active['results'] as $result) {
            if (is_array($result) && is_string($result['id'] ?? null)) {
                $completed[$result['id']] = true;
            }
        }
    }

    $plugins = [];
    foreach (glob(PLUGIN_INSTALLED_DIR . '/*.plg') ?: [] as $path) {
        $id = basename($path);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.plg$/D', $id) || str_contains($id, '..')) {
            continue;
        }
        $dom = pluginXml($path);
        $attrs = $dom?->attributes();
        $name = $attrs !== null ? trim((string) ($attrs['name'] ?? '')) : '';
        $author = $attrs !== null ? trim((string) ($attrs['author'] ?? '')) : '';
        $version = $attrs !== null ? trim((string) ($attrs['version'] ?? '')) : '';
        $pluginUrl = $attrs !== null ? trim((string) ($attrs['pluginURL'] ?? '')) : '';
        $saved = is_array($cache['plugins'][$id] ?? null) ? $cache['plugins'][$id] : [];
        $canCheck = $pluginUrl !== '' && !operatingSystemPlugin($id);
        $status = $canCheck ? (string) ($saved['status'] ?? 'unknown') : 'unsupported';
        if (!in_array($status, ['unknown', 'checking', 'current', 'updateAvailable', 'incompatible', 'error', 'unsupported'], true)) {
            $status = 'unknown';
        }
        if ($checking && !isset($completed[$id])) {
            $status = 'checking';
        }
        $plugins[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : pathinfo($id, PATHINFO_FILENAME),
            'author' => $author,
            'version' => $version,
            'canCheck' => $canCheck,
            'latestVersion' => is_string($saved['latestVersion'] ?? null) ? $saved['latestVersion'] : null,
            'updateNotes' => $status === 'updateAvailable' && is_string($saved['updateNotes'] ?? null)
                ? $saved['updateNotes']
                : null,
            'status' => $status,
            'statusMessage' => is_string($saved['statusMessage'] ?? null) ? $saved['statusMessage'] : null,
            'managed' => pluginManaged($id),
        ];
    }
    usort($plugins, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return ['plugins' => $plugins, 'checkedAt' => $cache['checkedAt'], 'activeJob' => $active];
}

// ---------- CA 应用商店(只读) ----------
// feed 官方地址体积约 20MB、顶层 applist 约 5000 条;插件项带 Plugin=true 与 PluginURL,
// 其余项带 Name/Repository/TemplateURL/Icon/Overview/CategoryList/Repo/Author/Support/Project。

/** 只保留 http/https 绝对 URL(挡 javascript:/data:/file: 等伪协议) */
function caAppsUrl(string $raw): string
{
    $url = trim($raw);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    return $url;
}

/** 任意富文本 → 纯文本:先整块去掉 script/style/iframe 等,再 strip_tags,解实体,压空白,限长 */
function caAppsPlainText(string $raw): string
{
    $text = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', ' ', $raw) ?? '';
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if (mb_strlen($text) > CA_APPS_DESC_MAX) {
        $text = mb_substr($text, 0, CA_APPS_DESC_MAX);
    }
    return $text;
}

/** 分类:数组或分隔字符串 → 去重字符串数组(上限 12 项,单项 ≤64 字符) */
function caAppsCategories(mixed $raw): array
{
    if (is_array($raw)) {
        $list = $raw;
    } elseif (is_string($raw)) {
        $list = preg_split('/[,;|]/', $raw) ?: [];
    } else {
        $list = [];
    }
    $out = [];
    foreach ($list as $category) {
        if (!is_string($category) && !is_numeric($category)) {
            continue;
        }
        $category = caAppsPlainText((string) $category);
        if ($category === '') {
            continue;
        }
        if (mb_strlen($category) > 64) {
            $category = mb_substr($category, 0, 64);
        }
        if (!in_array($category, $out, true)) {
            $out[] = $category;
        }
        if (count($out) >= 12) {
            break;
        }
    }
    return $out;
}

/** 名称键:只留字母数字并小写(容器名 / 模板名 / 插件名匹配用) */
function caAppsKey(string $raw): string
{
    return strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $raw) ?? '');
}

/** 仓库键:去协议、去 registry 前缀、去 tag/摘要,小写 */
function caAppsRepoKey(string $raw): string
{
    $repo = trim($raw);
    if ($repo === '') {
        return '';
    }
    $repo = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $repo) ?? $repo;
    $repo = preg_replace('#^[A-Za-z0-9.-]+\.[A-Za-z]{2,}(:\d+)?/#', '', $repo) ?? $repo;
    $repo = preg_replace('/@sha256:[0-9a-f]+$/i', '', $repo) ?? $repo;
    $repo = preg_replace('/:[A-Za-z0-9._-]+$/', '', $repo) ?? $repo;
    return strtolower(trim($repo, '/'));
}

/** 稳定 id:插件取 PluginURL 文件名,其余取 Repo/Repository/Name;最后兜底内容哈希 */
function caAppsId(array $item, bool $isPlugin): string
{
    $raw = '';
    if ($isPlugin) {
        $raw = (string) ($item['PluginURL'] ?? '');
        if ($raw !== '') {
            $path = (string) parse_url($raw, PHP_URL_PATH);
            $base = basename($path);
            if ($base !== '') {
                $raw = $base;
            }
        }
        if ($raw === '') {
            $raw = (string) ($item['Name'] ?? '');
        }
    } else {
        foreach (['Repo', 'Repository', 'Name'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                $raw = $value;
                break;
            }
        }
    }
    $raw = preg_replace('/\.plg$/i', '', $raw) ?? $raw;
    $id = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', $raw) ?? '');
    $id = trim($id, '-._');
    if ($id === '') {
        $id = 'app-' . substr(hash('sha256', (string) json_encode($item)), 0, 16);
    }
    return $id;
}

/** feed 单条 → 内部记录(白名单字段 + 内部 repo 键;repo 只留在缓存里,不进响应) */
function caAppsRecord(array $item): ?array
{
    $pluginRaw = $item['Plugin'] ?? false;
    $isPlugin = $pluginRaw === true
        || $pluginRaw === 1
        || $pluginRaw === '1'
        || strtolower((string) $pluginRaw) === 'true';
    $name = trim((string) ($item['Name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $description = trim((string) ($item['Description'] ?? ''));
    if ($description === '') {
        $description = (string) ($item['Overview'] ?? '');
    }
    $repo = trim((string) ($item['Repo'] ?? ''));
    $record = [
        'id' => caAppsId($item, $isPlugin),
        'name' => $name,
        'description' => caAppsPlainText($description),
        'icon' => caAppsUrl((string) ($item['Icon'] ?? '')),
        'categories' => caAppsCategories($item['CategoryList'] ?? null),
        'author' => caAppsPlainText((string) ($item['Author'] ?? '')),
        'repository' => caAppsUrl((string) ($item['Repository'] ?? '')),
        'pluginUrl' => $isPlugin ? caAppsUrl((string) ($item['PluginURL'] ?? '')) : '',
        'templateUrl' => caAppsUrl((string) ($item['TemplateURL'] ?? '')),
        'installed' => false,
        'type' => $isPlugin ? 'plugin' : 'docker',
        'support' => caAppsUrl((string) ($item['Support'] ?? '')),
        'project' => caAppsUrl((string) ($item['Project'] ?? '')),
        'repo' => $repo,
    ];
    return $record;
}

/** 当前已安装状态上下文:插件 .plg 与运行/停止的实际容器(全部只读,不看模板残留) */
function caAppsInstalledContext(): array
{
    $plugins = [];
    $pluginNames = [];
    foreach (glob(PLUGIN_INSTALLED_DIR . '/*.plg') ?: [] as $path) {
        $id = basename($path);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.plg$/D', $id)) {
            continue;
        }
        $plugins[caAppsKey(pathinfo($id, PATHINFO_FILENAME))] = true;
        $dom = pluginXml($path);
        $attrs = $dom?->attributes();
        $name = $attrs !== null ? trim((string) ($attrs['name'] ?? '')) : '';
        if ($name !== '') {
            $pluginNames[caAppsKey($name)] = true;
        }
    }

    $images = [];
    $containers = [];
    $lines = [];
    exec(
        PATH_ENV . ' ' . DOCKER . ' ps -a --format ' . escapeshellarg('{{.Names}}|{{.Image}}') . ' 2>/dev/null',
        $lines
    );
    foreach ($lines as $line) {
        $parts = explode('|', trim($line), 2);
        if (count($parts) !== 2) {
            continue;
        }
        $image = caAppsRepoKey($parts[1]);
        if ($image !== '') {
            $images[$image] = true;
        }
        $container = caAppsKey($parts[0]);
        if ($container !== '') {
            $containers[$container] = true;
        }
    }

    return [
        'plugins' => $plugins,
        'pluginNames' => $pluginNames,
        'images' => $images,
        'containers' => $containers,
    ];
}

/**
 * 是否当前已安装:插件只认 .plg id/名称精确命中;Docker 只认实际容器的
 * 镜像仓库/名称精确命中。用户模板可能残留、社区模板仅用于安装,均不算安装证据。
 */
function caAppsInstalled(string $id, string $name, string $repo, bool $isPlugin, array $ctx): bool
{
    $nameKey = caAppsKey($name);
    if ($isPlugin) {
        if (caAppsKey($id) !== '' && isset($ctx['plugins'][caAppsKey($id)])) {
            return true;
        }
        return $nameKey !== '' && mb_strlen($nameKey) >= 3 && isset($ctx['pluginNames'][$nameKey]);
    }
    $repoKey = caAppsRepoKey($repo);
    if ($repoKey !== '' && isset($ctx['images'][$repoKey])) {
        return true;
    }
    if ($nameKey !== '' && mb_strlen($nameKey) >= 3 && isset($ctx['containers'][$nameKey])) {
        return true;
    }
    return false;
}

/** 内部记录 → 响应记录:严格只复制白名单字段,并按当前状态算 installed */
function caAppsApplyInstalled(array $records, array $ctx): array
{
    $out = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $isPlugin = (string) ($record['type'] ?? '') === 'plugin';
        $installed = caAppsInstalled(
            (string) ($record['id'] ?? ''),
            (string) ($record['name'] ?? ''),
            (string) ($record['repo'] ?? ''),
            $isPlugin,
            $ctx
        );
        $item = [];
        foreach (CA_APPS_FIELDS as $field) {
            if ($field === 'installed') {
                $item[$field] = $installed;
                continue;
            }
            $value = $record[$field] ?? null;
            if ($field === 'categories') {
                $item[$field] = is_array($value) ? array_values($value) : [];
                continue;
            }
            $item[$field] = is_string($value) ? $value : '';
        }
        $out[] = $item;
    }
    return $out;
}

/** 读缓存:null = 无可用缓存;否则 ['cachedAt'=>int,'records'=>array] */
function caAppsReadCache(): ?array
{
    $raw = readJsonFile(CA_APPS_CACHE_FILE);
    if (!is_array($raw)) {
        return null;
    }
    $cachedAt = (int) ($raw['cachedAt'] ?? 0);
    $records = is_array($raw['records'] ?? null) ? array_values($raw['records']) : [];
    if ($cachedAt <= 0 || $records === []) {
        return null;
    }
    return ['cachedAt' => $cachedAt, 'records' => $records];
}

/** 原子写缓存:同目录 tmp + rename;失败不影响旧缓存 */
function caAppsWriteCache(int $cachedAt, array $records): bool
{
    $dir = dirname(CA_APPS_CACHE_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode(
        ['cachedAt' => $cachedAt, 'records' => $records],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        return false;
    }
    $tmp = CA_APPS_CACHE_FILE . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, CA_APPS_CACHE_FILE)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** 只读 HTTP JSON:cURL 优先(连接/总超时 + 写回调强制大小上限),无 cURL 时流式分块读。 */
function caAppsHttpGet(
    string $url,
    int $connectTimeout = CA_APPS_CONNECT_TIMEOUT_SEC,
    int $totalTimeout = CA_APPS_TOTAL_TIMEOUT_SEC,
    int $maxBytes = CA_APPS_MAX_BYTES
): array {
    if (function_exists('curl_init')) {
        return caAppsCurlGet($url, $connectTimeout, $totalTimeout, $maxBytes);
    }
    return caAppsStreamGet($url, $totalTimeout, $maxBytes);
}

function caAppsCurlGet(
    string $url,
    int $connectTimeout = CA_APPS_CONNECT_TIMEOUT_SEC,
    int $totalTimeout = CA_APPS_TOTAL_TIMEOUT_SEC,
    int $maxBytes = CA_APPS_MAX_BYTES
): array {
    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'body' => '', 'error' => 'curl 初始化失败'];
    }
    $body = '';
    $overflow = false;
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $totalTimeout,
        CURLOPT_USERAGENT => 'unraid-mobile/compose-api',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$overflow, $maxBytes): int {
            if ($overflow) {
                return 0;
            }
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                $overflow = true;
                return 0;
            }
            return strlen($chunk);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($ch, constant('CURLOPT_PROTOCOLS_STR'), 'http,https');
    } elseif (defined('CURLOPT_PROTOCOLS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($overflow) {
        return ['ok' => false, 'body' => '', 'error' => '响应超过大小上限 ' . $maxBytes . ' 字节'];
    }
    if ($result === false) {
        return ['ok' => false, 'body' => '', 'error' => $error !== '' ? $error : ('HTTP 失败 errno=' . $errno)];
    }
    if ($code !== 200) {
        return ['ok' => false, 'body' => '', 'error' => 'HTTP ' . $code];
    }
    return ['ok' => true, 'body' => $body, 'error' => ''];
}

function caAppsStreamGet(
    string $url,
    int $totalTimeout = CA_APPS_TOTAL_TIMEOUT_SEC,
    int $maxBytes = CA_APPS_MAX_BYTES
): array {
    $context = stream_context_create([
        'http' => [
            'timeout' => $totalTimeout,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: unraid-mobile/compose-api\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $fh = @fopen($url, 'rb', false, $context);
    if ($fh === false) {
        return ['ok' => false, 'body' => '', 'error' => '打开 feed 失败'];
    }
    $body = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 262144);
        if ($chunk === false) {
            fclose($fh);
            return ['ok' => false, 'body' => '', 'error' => '读取 feed 失败'];
        }
        $body .= $chunk;
        if (strlen($body) > $maxBytes) {
            fclose($fh);
            return ['ok' => false, 'body' => '', 'error' => '响应超过大小上限 ' . $maxBytes . ' 字节'];
        }
    }
    $meta = stream_get_meta_data($fh);
    fclose($fh);
    $status = 0;
    foreach (($meta['wrapper_data'] ?? []) as $header) {
        if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($status !== 0 && $status !== 200) {
        return ['ok' => false, 'body' => '', 'error' => 'HTTP ' . $status];
    }
    return ['ok' => true, 'body' => $body, 'error' => ''];
}

/** feed 文本 → 内部记录数组(顶层 applist) */
function caAppsParseFeed(string $body): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'records' => [], 'error' => 'feed 不是合法 JSON'];
    }
    $list = $data['applist'] ?? null;
    if (!is_array($list)) {
        foreach (['apps', 'applications'] as $key) {
            if (is_array($data[$key] ?? null)) {
                $list = $data[$key];
                break;
            }
        }
    }
    if (!is_array($list)) {
        return ['ok' => false, 'records' => [], 'error' => 'feed 缺少 applist 数组'];
    }
    $records = [];
    $seen = [];
    foreach ($list as $item) {
        if (!is_array($item)) {
            continue;
        }
        $record = caAppsRecord($item);
        if ($record === null) {
            continue;
        }
        if (isset($seen[$record['id']])) {
            continue;
        }
        $seen[$record['id']] = true;
        $records[] = $record;
    }
    usort($records, static fn(array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
    return ['ok' => true, 'records' => $records, 'error' => ''];
}

/** 拉远端 feed(不落盘),失败返回原因 */
function caAppsFetch(): array
{
    $http = caAppsHttpGet(CA_APPS_FEED_URL);
    if (!$http['ok']) {
        return ['ok' => false, 'records' => [], 'error' => $http['error']];
    }
    return caAppsParseFeed($http['body']);
}

/**
 * CA 应用列表:缓存优先(短 TTL,默认不碰远端);refresh=1 强制重取。
 * 拉取失败保留旧缓存并标 stale;无任何缓存才报错。
 */
function caAppsResponse(bool $refresh): array
{
    $cache = caAppsReadCache();
    $now = time();
    if (!$refresh && $cache !== null && ($now - $cache['cachedAt']) < CA_APPS_CACHE_TTL) {
        return [
            'apps' => caAppsApplyInstalled($cache['records'], caAppsInstalledContext()),
            'cachedAt' => $cache['cachedAt'],
            'stale' => false,
        ];
    }
    $fetched = caAppsFetch();
    if ($fetched['ok']) {
        $records = $fetched['records'];
        if ($records === []) {
            $error = 'feed 解析出 0 条应用';
            error_log('unraid-mobile compose-api: CA 应用 ' . $error);
            if ($cache !== null) {
                return [
                    'apps' => caAppsApplyInstalled($cache['records'], caAppsInstalledContext()),
                    'cachedAt' => $cache['cachedAt'],
                    'stale' => true,
                ];
            }
            fail(502, 'CA 应用列表获取失败: ' . $error);
        }
        if (!caAppsWriteCache($now, $records)) {
            error_log('unraid-mobile compose-api: CA 应用缓存写入失败');
        }
        return [
            'apps' => caAppsApplyInstalled($records, caAppsInstalledContext()),
            'cachedAt' => $now,
            'stale' => false,
        ];
    }
    error_log('unraid-mobile compose-api: CA 应用 feed 拉取失败: ' . $fetched['error']);
    if ($cache !== null) {
        return [
            'apps' => caAppsApplyInstalled($cache['records'], caAppsInstalledContext()),
            'cachedAt' => $cache['cachedAt'],
            'stale' => true,
        ];
    }
    fail(502, 'CA 应用列表获取失败: ' . $fetched['error']);
}

// ---------- CA Docker 模板读取与官方安装 ----------

function caTemplateUrl(mixed $url): string
{
    if (!is_string($url) || $url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) {
        fail(400, '非法 CA 模板 URL');
    }
    $parts = parse_url($url);
    if (
        !is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !is_string($parts['host'] ?? null)
        || $parts['host'] === ''
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        fail(400, 'CA 模板 URL 必须是 HTTPS 且不允许用户信息');
    }
    $apps = caAppsResponse(false)['apps'];
    foreach ($apps as $app) {
        if (
            ($app['type'] ?? '') === 'docker'
            && is_string($app['templateUrl'] ?? null)
            && hash_equals($app['templateUrl'], $url)
        ) {
            return $url;
        }
    }
    fail(400, '模板 URL 不在当前 CA feed 白名单中');
}

function caTemplateHttpGet(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        if ($ch === false) {
            fail(502, 'CA 模板下载初始化失败');
        }
        $body = '';
        $overflow = false;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => CA_APPS_CONNECT_TIMEOUT_SEC,
            CURLOPT_TIMEOUT => CA_APPS_TOTAL_TIMEOUT_SEC,
            CURLOPT_USERAGENT => 'unraid-mobile/compose-api',
            CURLOPT_HTTPHEADER => ['Accept: application/xml,text/xml'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$overflow): int {
                $body .= $chunk;
                if (strlen($body) > CA_TEMPLATE_MAX_BYTES) {
                    $overflow = true;
                    return 0;
                }
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, constant('CURLOPT_PROTOCOLS_STR'), 'https');
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $result = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($overflow) {
            fail(502, 'CA 模板超过大小上限');
        }
        if ($result === false) {
            fail(502, 'CA 模板下载失败: ' . ($error !== '' ? $error : 'cURL 错误'));
        }
        if ($code !== 200) {
            fail(502, 'CA 模板下载返回 HTTP ' . $code . '(不允许重定向)');
        }
        return $body;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => CA_APPS_TOTAL_TIMEOUT_SEC,
            'follow_location' => 0,
            'max_redirects' => 0,
            'ignore_errors' => true,
            'header' => "Accept: application/xml,text/xml\r\nUser-Agent: unraid-mobile/compose-api\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $fh = @fopen($url, 'rb', false, $context);
    if ($fh === false) {
        fail(502, 'CA 模板下载失败');
    }
    $body = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 262144);
        if ($chunk === false) {
            fclose($fh);
            fail(502, 'CA 模板读取失败');
        }
        $body .= $chunk;
        if (strlen($body) > CA_TEMPLATE_MAX_BYTES) {
            fclose($fh);
            fail(502, 'CA 模板超过大小上限');
        }
    }
    $meta = stream_get_meta_data($fh);
    fclose($fh);
    $status = 0;
    foreach (($meta['wrapper_data'] ?? []) as $header) {
        if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($status !== 200) {
        fail(502, 'CA 模板下载返回 HTTP ' . $status . '(不允许重定向)');
    }
    return $body;
}

function caTemplateXml(string $url): SimpleXMLElement
{
    $body = caTemplateHttpGet($url);
    $dom = @simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
    if (!$dom instanceof SimpleXMLElement || $dom->getName() !== 'Container') {
        fail(422, 'CA 模板不是有效的 Container XML');
    }
    if (trim((string) $dom->Name) === '' || trim((string) $dom->Repository) === '') {
        fail(422, 'CA 模板缺少 Name 或 Repository');
    }
    if ((string) $dom['version'] !== '2') {
        fail(422, '仅支持 version=2 的 CA Docker 模板');
    }
    return $dom;
}

function caTemplateData(string $url): array
{
    $dom = caTemplateXml($url);
    $configs = [];
    foreach ($dom->Config as $node) {
        $attrs = $node->attributes();
        $attr = static fn(string $key): string => (string) ($attrs[$key] ?? '');
        $default = $attr('Default');
        $raw = (string) $node;
        $value = $raw !== '' ? $raw : $default;
        $mask = in_array(strtolower($attr('Mask')), ['true', '1', 'yes', 'on'], true);
        $configs[] = [
            'target' => $attr('Target'),
            'name' => $attr('Name'),
            'default' => $mask ? '' : $default,
            'value' => $mask ? '' : $value,
            'type' => $attr('Type'),
            'mode' => $attr('Mode'),
            'description' => $attr('Description'),
            'display' => !in_array(strtolower($attr('Display')), ['never', 'hidden', 'hide', 'false', '0', 'no', 'off'], true),
            'required' => in_array(strtolower($attr('Required')), ['true', '1', 'yes', 'on'], true),
            'mask' => $mask,
        ];
    }
    return [
        'name' => trim((string) $dom->Name),
        'repository' => trim((string) $dom->Repository),
        'icon' => trim((string) $dom->Icon),
        'configs' => $configs,
    ];
}

function caDockerContainerExists(string $name): bool
{
    $lines = [];
    $code = 1;
    exec(
        PATH_ENV . ' ' . DOCKER . ' inspect --format ' . escapeshellarg('{{.Name}}')
        . ' ' . escapeshellarg($name) . ' 2>/dev/null',
        $lines,
        $code
    );
    return $code === 0 && trim((string) ($lines[0] ?? '')) === '/' . $name;
}

/**
 * 以 CA 模板 XML 为基础套用用户值,生成 Unraid 用户模板(my-<name>.xml)。
 * 不再伪造 $_POST 并 include 官方 CreateDocker.php:直接改写模板节点,
 * 产物与官方 postToXML 同构,交由 update_container 的 xmlToCommand 读取,
 * 与容器更新共用同一条官方路径。本函数只校验并返回,不写文件;
 * 落盘在 startCaInstallJob 完成全部冲突检查之后,避免覆盖他源同名模板。
 */
function caInstallUserTemplate(SimpleXMLElement $dom, string $url, array $values): array
{
    $name = trim((string) $dom->Name);
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name)) {
        fail(422, 'CA 模板容器名不合法');
    }
    $repository = trim((string) $dom->Repository);

    $configs = [];
    $allowed = [];
    $secrets = [];
    foreach ($dom->Config as $node) {
        $attrs = $node->attributes();
        $attr = static fn(string $key): string => (string) ($attrs[$key] ?? '');
        $target = $attr('Target');
        if ($target !== '') {
            if (isset($allowed[$target])) {
                fail(422, 'CA 模板包含重复 Config Target');
            }
            $allowed[$target] = true;
        }
        $configs[] = [$node, $attr];
    }
    foreach ($values as $target => $value) {
        // JSON 数字形式 Target(如端口 8001)解码为 PHP 数组后会变成整数键。
        if (is_int($target)) {
            $target = (string) $target;
        }
        if (!is_string($target) || !isset($allowed[$target])) {
            fail(400, 'values 包含模板未声明的 Config Target');
        }
        if (!is_scalar($value) || strlen((string) $value) > 65536) {
            fail(400, 'Config 值必须是长度不超过 65536 的标量');
        }
    }

    foreach ($configs as [$node, $attr]) {
        $target = $attr('Target');
        $default = $attr('Default');
        $raw = (string) $node;
        $original = $raw !== '' ? $raw : $default;
        $value = $target !== '' && array_key_exists($target, $values) ? (string) $values[$target] : $original;
        $required = in_array(strtolower($attr('Required')), ['true', '1', 'yes', 'on'], true);
        $mask = in_array(strtolower($attr('Mask')), ['true', '1', 'yes', 'on'], true);
        if ($required && trim($value) === '') {
            if ($mask && $original !== '') {
                $value = $original;
            } else {
                fail(400, '必填 Config 不能为空: ' . $attr('Name'));
            }
        }
        if ($mask) {
            foreach (array_unique([$default, $original, $value]) as $secret) {
                if ($secret !== '') {
                    $secrets[] = $secret;
                }
            }
        }
        // 直接改写模板节点文本,保留 Name/Target/Mode/Type/Display/Required/Mask 等全部属性,
        // 使 xmlToCommand 与 WebGUI 读到的结构与官方 CreateDocker.php 产物一致
        $node[0] = $value;
    }

    $dom->Shell = trim((string) $dom->Shell) !== '' ? (string) $dom->Shell : 'sh';
    $dom->TemplateURL = $url;
    $dom->DateInstalled = (string) time();

    return [
        'name' => $name,
        'repository' => $repository,
        'templatePath' => TEMPLATE_USER_DIR . '/my-' . $name . '.xml',
        'templateUrl' => $url,
        'secrets' => array_values(array_unique($secrets)),
        'dom' => $dom,
    ];
}

function caInstallJobScript(array $job): string
{
    $template = <<<'PHP'
<?php

declare(strict_types=1);

const CA_JOB_WORK_DIR = __WORK_DIR__;
const CA_JOB_UPDATE_CONTAINER = __UPDATE_CONTAINER__;
const CA_JOB_DOCKER = __DOCKER__;
const CA_JOB_AUDIT = __AUDIT__;
$job = __JOB__;
$logPath = CA_JOB_WORK_DIR . '/' . $job['id'] . '.log';
$statusPath = CA_JOB_WORK_DIR . '/' . $job['id'] . '.json';
$resultPath = CA_JOB_WORK_DIR . '/' . $job['id'] . '.result.json';

function caWorkerJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        return;
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }
}

function caWorkerRedact(string $text, array $secrets): string
{
    foreach ($secrets as $secret) {
        if (!is_string($secret) || $secret === '') {
            continue;
        }
        $variants = [
            $secret,
            htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            addslashes($secret),
            addslashes(htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        ];
        $text = str_replace(array_unique($variants), '********', $text);
    }
    return $text;
}

function caWorkerLog(string $path, string $message, array $secrets): void
{
    if ($message !== '') {
        @file_put_contents($path, caWorkerRedact($message, $secrets), FILE_APPEND | LOCK_EX);
        @chmod($path, 0600);
    }
}

function caWorkerContainerExists(string $name): bool
{
    $lines = [];
    $code = 1;
    exec(CA_JOB_DOCKER . ' inspect --format ' . escapeshellarg('{{.Name}}') . ' ' . escapeshellarg($name) . ' 2>/dev/null', $lines, $code);
    return $code === 0 && trim((string) ($lines[0] ?? '')) === '/' . $name;
}

function caWorkerContainerState(string $name): ?array
{
    $lines = [];
    $code = 1;
    exec(CA_JOB_DOCKER . ' inspect --format ' . escapeshellarg('{{.State.Status}}|{{.State.Error}}') . ' ' . escapeshellarg($name) . ' 2>/dev/null', $lines, $code);
    if ($code !== 0) {
        return null;
    }
    [$status, $error] = array_pad(explode('|', trim((string) ($lines[0] ?? '')), 2), 2, '');
    return $status !== '' ? ['status' => $status, 'error' => $error] : null;
}

function caWorkerTemplateExists(string $name): bool
{
    foreach (glob('/boot/config/plugins/dockerMan/templates-user/my-*.xml') ?: [] as $path) {
        $dom = @simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
        if ($dom instanceof SimpleXMLElement && trim((string) $dom->Name) === $name) {
            return true;
        }
    }
    return false;
}

function caWorkerTemplateMatchesUrl(string $name, string $url): bool
{
    foreach (glob('/boot/config/plugins/dockerMan/templates-user/my-*.xml') ?: [] as $path) {
        $dom = @simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
        if ($dom instanceof SimpleXMLElement && trim((string) $dom->Name) === $name) {
            return hash_equals($url, trim((string) $dom->TemplateURL));
        }
    }
    return false;
}

function caWorkerAudit(string $name, string $result, string $ip): void
{
    $size = @filesize(CA_JOB_AUDIT);
    if ($size !== false && $size > 1048576) {
        @rename(CA_JOB_AUDIT, CA_JOB_AUDIT . '.old');
    }
    @file_put_contents(
        CA_JOB_AUDIT,
        sprintf('[%s] action=cainstall stack=%s result=%s ip=%s\n', date('c'), $name, $result, $ip),
        FILE_APPEND | LOCK_EX
    );
}

$error = null;
$started = false;
register_shutdown_function(static function () use (&$error, &$started, $job, $logPath, $statusPath, $resultPath): void {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    if ($error !== null) {
        caWorkerLog($logPath, "\n[unraid-mobile] " . $error . "\n", $job['secrets']);
    }
    $state = $started ? caWorkerContainerState($job['name']) : null;
    $exists = $state !== null && $state['status'] === 'running';
    $failure = $error ?? ($state === null
        ? '官方流程结束,但最终未检测到同名 Docker 容器'
        : ($state['error'] !== '' ? $state['error'] : '官方流程结束,但容器未运行(当前状态: ' . $state['status'] . ')'));
    $result = [
        'success' => $exists,
        'name' => $job['name'],
        'finishedAt' => date('c'),
        'error' => $exists ? null : caWorkerRedact($failure, $job['secrets']),
    ];
    caWorkerLog($logPath, "\n[unraid-mobile] " . ($exists ? '安装成功,已确认容器运行' : '安装失败: ' . $result['error']) . "\n", $job['secrets']);
    caWorkerJson($resultPath, $result);
    $status = json_decode((string) @file_get_contents($statusPath), true);
    if (!is_array($status)) {
        $status = ['id' => $job['id'], 'name' => $job['name']];
    }
    $status['running'] = false;
    $status['result'] = $result;
    $status['finishedAt'] = $result['finishedAt'];
    caWorkerJson($statusPath, $status);
    caWorkerAudit($job['name'], $exists ? 'ok' : 'fail', $job['ip']);
    $lockPath = CA_JOB_WORK_DIR . '/cainstall.lock';
    if (trim((string) @file_get_contents($lockPath)) === $job['id']) {
        @unlink($lockPath);
    }
    @unlink(__FILE__);
});

ignore_user_abort(true);
set_time_limit(0);
caWorkerLog($logPath, '[' . date('c') . "] 开始官方 CA 安装: " . $job['name'] . "\n", $job['secrets']);
ob_start(static function (string $chunk) use ($logPath, $job): string {
    caWorkerLog($logPath, $chunk, $job['secrets']);
    return '';
}, 8192);
try {
    if (caWorkerContainerExists($job['name'])) {
        throw new RuntimeException('同名 Docker 容器已存在,拒绝覆盖');
    }
    if (caWorkerTemplateExists($job['name']) && !caWorkerTemplateMatchesUrl($job['name'], $job['templateUrl'])) {
        throw new RuntimeException('同名用户模板来自不同地址,拒绝覆盖');
    }
    if (!is_file(CA_JOB_UPDATE_CONTAINER)) {
        throw new RuntimeException('官方 update_container 不存在');
    }

    // 1) 先自己 docker pull 拿进度日志:官方 update_container 的 pull 输出走 nchan,App 读不到
    $repo = $job['repository'];
    if (!preg_match('/:\S+$/', $repo)) {
        $repo .= ':latest';
    }
    caWorkerLog($logPath, '[unraid-mobile] docker pull ' . $repo . "\n", $job['secrets']);
    $pull = @popen(CA_JOB_DOCKER . ' pull ' . escapeshellarg($repo) . ' 2>&1', 'r');
    if ($pull === false) {
        throw new RuntimeException('无法执行 docker pull');
    }
    while (($line = fgets($pull)) !== false) {
        caWorkerLog($logPath, $line, $job['secrets']);
    }
    $pullRc = pclose($pull);
    if ($pullRc !== 0) {
        throw new RuntimeException('docker pull 失败(退出码 ' . $pullRc . ')');
    }

    // 2) 官方 update_container 按用户模板创建并启动(ca_docker_run_override 强制 run -d),
    //    与容器更新共用同一条官方路径,不再 include CreateDocker.php
    caWorkerLog($logPath, '[unraid-mobile] update_container ' . $job['name'] . " ca_docker_run_override\n", $job['secrets']);
    $out = [];
    $code = 0;
    exec('/usr/bin/php ' . escapeshellarg(CA_JOB_UPDATE_CONTAINER)
        . ' ' . escapeshellarg($job['name']) . ' ca_docker_run_override 2>&1', $out, $code);
    foreach ($out as $line) {
        caWorkerLog($logPath, $line . "\n", $job['secrets']);
    }
    if ($code !== 0) {
        throw new RuntimeException('update_container 失败(退出码 ' . $code . ')');
    }
    $started = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
}
PHP;
    return strtr($template, [
        '__WORK_DIR__' => var_export(PLUGIN_WORK_DIR, true),
        '__UPDATE_CONTAINER__' => var_export(DOCKER_UPDATE_SCRIPT, true),
        '__DOCKER__' => var_export(DOCKER, true),
        '__AUDIT__' => var_export(AUDIT_LOG, true),
        '__JOB__' => var_export($job, true),
    ]);
}

function startCaInstallJob(array $prepared): string
{
    if (!is_file(DOCKER_UPDATE_SCRIPT)) {
        fail(503, '官方 update_container 不存在');
    }
    if (!is_dir(PLUGIN_WORK_DIR) && !@mkdir(PLUGIN_WORK_DIR, 0700, true) && !is_dir(PLUGIN_WORK_DIR)) {
        fail(500, 'CA 任务目录创建失败');
    }
    @chmod(PLUGIN_WORK_DIR, 0700);
    $id = bin2hex(random_bytes(16));
    $lock = @fopen(CA_INSTALL_LOCK, 'x');
    if ($lock === false) {
        audit('cainstall', $prepared['name'], 'fail');
        fail(409, '已有 CA 安装任务正在进行中');
    }
    if (@fwrite($lock, $id) === false) {
        fclose($lock);
        @unlink(CA_INSTALL_LOCK);
        fail(500, 'CA 任务锁写入失败');
    }
    fclose($lock);
    // 容器与模板来源冲突检查已在 startCaInstall 完成,此处才落盘用户模板,
    // 避免先覆盖他源同名模板再被 findUserTemplate 误判为同源
    $dom = $prepared['dom'];
    if (@$dom->asXML($prepared['templatePath']) === false) {
        @unlink(CA_INSTALL_LOCK);
        fail(500, '用户模板写入失败: ' . $prepared['templatePath']);
    }
    @chmod($prepared['templatePath'], 0644);
    $job = [
        'id' => $id,
        'name' => $prepared['name'],
        'repository' => $prepared['repository'],
        'templatePath' => $prepared['templatePath'],
        'templateUrl' => $prepared['templateUrl'],
        'secrets' => $prepared['secrets'],
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
    ];
    $script = PLUGIN_WORK_DIR . '/' . $id . '.php';
    $status = [
        'id' => $id,
        'name' => $prepared['name'],
        'createdAt' => date('c'),
        'running' => true,
        'result' => null,
    ];
    try {
        if (@file_put_contents($script, caInstallJobScript($job), LOCK_EX) === false) {
            throw new RuntimeException('CA worker 写入失败');
        }
        @chmod($script, 0700);
        writePluginJson(PLUGIN_WORK_DIR . '/' . $id . '.json', $status);
        @file_put_contents(PLUGIN_WORK_DIR . '/' . $id . '.log', '', LOCK_EX);
        @chmod(PLUGIN_WORK_DIR . '/' . $id . '.log', 0600);
    } catch (Throwable $e) {
        @unlink($script);
        @unlink(CA_INSTALL_LOCK);
        fail(500, $e->getMessage());
    }
    audit('cainstall', $prepared['name'], 'queued');
    exec(PATH_ENV . ' nohup /usr/bin/php ' . escapeshellarg($script) . ' > /dev/null 2>&1 < /dev/null &');
    return $id;
}

function startCaInstall(array $body): string
{
    $url = caTemplateUrl($body['templateUrl'] ?? null);
    $dom = caTemplateXml($url);
    $values = $body['values'] ?? [];
    if (!is_array($values)) {
        fail(400, 'values 必须是 TARGET:value 对象');
    }
    $prepared = caInstallUserTemplate($dom, $url, $values);
    $name = $prepared['name'];
    if (caDockerContainerExists($name)) {
        audit('cainstall', $name, 'fail');
        fail(409, '同名 Docker 容器已存在,只允许安装新容器');
    }
    $userTemplate = findUserTemplate($name);
    if ($userTemplate !== null && !userTemplateMatchesUrl($userTemplate, $url)) {
        audit('cainstall', $name, 'fail');
        fail(409, '同名用户模板来自不同地址,不允许更新或覆盖');
    }
    return startCaInstallJob($prepared);
}

function readCaJob(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
        return null;
    }
    $job = readJsonFile(PLUGIN_WORK_DIR . '/' . $id . '.json');
    if (!is_array($job) || ($job['id'] ?? null) !== $id) {
        return null;
    }
    return [
        'id' => $id,
        'name' => (string) ($job['name'] ?? ''),
        'running' => ($job['running'] ?? false) === true,
        'log' => tailFile(PLUGIN_WORK_DIR . '/' . $id . '.log'),
        'result' => is_array($job['result'] ?? null) ? $job['result'] : null,
    ];
}

function validatePluginInstallUrl(mixed $url): array
{
    $url = (string) $url;
    $parts = parse_url($url);
    if (
        !is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !is_string($parts['host'] ?? null)
        || $parts['host'] === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || !preg_match('/\.plg$/D', (string) ($parts['path'] ?? ''))
    ) {
        fail(400, '插件 URL 必须是 HTTPS 且路径以 .plg 结尾,不允许用户信息、query 或 fragment');
    }
    $id = pluginId(basename($parts['path']));
    if (pluginInstalled($id)) {
        fail(409, '插件已安装,不覆盖: ' . $id);
    }
    $safeUrl = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parts['path'];
    return [$url, $safeUrl, $id];
}

function startPluginJob(string $operation, array $names = [], ?string $url = null, ?string $safeUrl = null): string
{
    if (!is_file(PLUGIN_WORKER)) {
        fail(503, '插件 worker 未安装,请重跑 install-compose-api.sh');
    }
    if (!is_dir(PLUGIN_WORK_DIR) && !@mkdir(PLUGIN_WORK_DIR, 0700, true) && !is_dir(PLUGIN_WORK_DIR)) {
        fail(500, '插件任务目录创建失败');
    }
    @chmod(PLUGIN_WORK_DIR, 0700);
    $id = bin2hex(random_bytes(16));
    $lock = @fopen(PLUGIN_WORK_DIR . '/active.lock', 'x');
    if ($lock === false) {
        audit($operation, $names[0] ?? '-', 'fail');
        fail(409, '已有插件任务正在进行中');
    }
    if (@fwrite($lock, $id) === false) {
        fclose($lock);
        @unlink(PLUGIN_WORK_DIR . '/active.lock');
        fail(500, '插件任务锁写入失败');
    }
    fclose($lock);
    $job = [
        'id' => $id,
        'operation' => $operation,
        'state' => 'queued',
        'current' => 0,
        'total' => count($names),
        'results' => [],
        'error' => null,
        'names' => array_values($names),
        'url' => $url,
        'safeUrl' => $safeUrl,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '-'),
        'createdAt' => date('c'),
    ];
    try {
        writePluginJson(PLUGIN_WORK_DIR . '/' . $id . '.json', $job);
    } catch (Throwable $e) {
        @unlink(PLUGIN_WORK_DIR . '/active.lock');
        fail(500, $e->getMessage());
    }
    audit($operation, $safeUrl ?? $names[0] ?? '-', 'queued');
    exec(PATH_ENV . ' nohup /usr/bin/php ' . escapeshellarg(PLUGIN_WORKER) . ' ' . escapeshellarg($id) . ' > /dev/null 2>&1 &');
    return $id;
}

// ---------- 磁盘唤醒 ----------

/**
 * 解析 emhttp 的 ini 状态文件(段落 + key="value"),返回 [段名 => [键 => 值]]。
 * 段名去引号、键名统一小写、值去引号;注释行与空行跳过。
 */
function parseStateIni(string $raw): array
{
    $sections = [];
    $current = '';
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^\[(.*)\]$/', $line, $m)) {
            $current = trim(trim($m[1]), "\"'");
            if (!isset($sections[$current])) {
                $sections[$current] = [];
            }
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_.-]+)\s*=\s*(.*)$/', $line, $m)) {
            $value = trim($m[2]);
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $sections[$current][strtolower($m[1])] = $value;
        }
    }
    return $sections;
}

/** 在 ini 状态里取值(根段优先,其次任意段);没有则 null */
function stateIniValue(array $sections, string $key): ?string
{
    $key = strtolower($key);
    if (isset($sections[''][$key])) {
        return (string) $sections[''][$key];
    }
    foreach ($sections as $slot) {
        if (isset($slot[$key])) {
            return (string) $slot[$key];
        }
    }
    return null;
}

/** 读 emhttp 磁盘状态文件(纯文件读);读不到返回 null(由调用方决定报错口径) */
function readDiskStateIni(): ?array
{
    $raw = @file_get_contents(DISKS_INI_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    return parseStateIni($raw);
}

/**
 * 选目标盘:必须同时满足 —— 阵列数据/校验盘(type=Data|Parity)、
 * 当前已停转(spundown=1)、状态 DISK_OK、有设备名(device 非空)、
 * 盘名形如 diskN 或 parity/parityN。HDD/SSD 均包含;cache/flash/未分配/禁用/正在转的盘排除。
 */
function diskWakeTargets(array $sections): array
{
    $targets = [];
    foreach ($sections as $section => $slot) {
        $name = trim((string) ($slot['name'] ?? $section));
        if (!preg_match('/^(?:disk[1-9][0-9]*|parity[0-9]*)$/', $name)) {
            continue;
        }
        if (!in_array(strtolower(trim((string) ($slot['type'] ?? ''))), ['data', 'parity'], true)) {
            continue;
        }
        // spundown=1 = 当前已停转(unraid-api 同口径:spundown==='0' 才是在转)
        $spundown = strtolower(trim((string) ($slot['spundown'] ?? '')));
        if ($spundown !== '1' && $spundown !== 'true') {
            continue;
        }
        if (strcasecmp(trim((string) ($slot['status'] ?? '')), 'DISK_OK') !== 0) {
            continue;
        }
        $device = trim((string) ($slot['device'] ?? ''));
        if ($device === '') {
            continue;
        }
        $targets[] = ['name' => $name, 'device' => $device];
    }
    usort(
        $targets,
        static fn(array $a, array $b): int => (int) substr($a['name'], 4) <=> (int) substr($b['name'], 4)
    );
    return $targets;
}

/** 当前转动状态:name => 是否已在转(spundown=0);字段缺失按未知处理(算未在转) */
function diskSpinState(array $sections): array
{
    $state = [];
    foreach ($sections as $section => $slot) {
        $name = trim((string) ($slot['name'] ?? $section));
        if ($name === '') {
            continue;
        }
        $spundown = strtolower(trim((string) ($slot['spundown'] ?? '')));
        $state[$name] = $spundown === '0' || $spundown === 'false';
    }
    return $state;
}

/**
 * 前置条件(emhttp 数组状态 var.ini):
 * fsState 必须 Started(数组在跑)、mdResync 必须 0(不在同步);
 * spinupGroups=yes 时官方按组起转,会连带唤醒本次目标之外的盘 → 直接拒绝(409)。
 */
function checkArrayReady(): void
{
    $raw = @file_get_contents(ARRAY_VAR_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        audit('diskwake', '-', 'fail');
        fail(503, '无法读取数组状态文件: ' . ARRAY_VAR_FILE);
    }
    $var = parseStateIni($raw);

    $fsState = trim((string) stateIniValue($var, 'fsState'));
    if (strcasecmp($fsState, 'Started') !== 0) {
        audit('diskwake', '-', 'fail');
        fail(409, '数组未处于运行状态(fsState=' . ($fsState !== '' ? $fsState : '-') . '),拒绝唤醒磁盘');
    }

    $mdResync = trim((string) stateIniValue($var, 'mdResync'));
    if ($mdResync === '' || (float) $mdResync !== 0.0) {
        audit('diskwake', '-', 'fail');
        fail(409, '阵列正在同步或同步状态未知(mdResync=' . ($mdResync !== '' ? $mdResync : '-') . '),拒绝唤醒磁盘');
    }

    $spinupGroups = strtolower(trim((string) stateIniValue($var, 'spinupGroups')));
    if ($spinupGroups === 'yes' || $spinupGroups === 'true' || $spinupGroups === '1') {
        audit('diskwake', '-', 'fail');
        fail(409, '已启用磁盘分组启动(spinupGroups=yes),会连带唤醒范围外磁盘,已拒绝');
    }
}

/**
 * 磁盘唤醒:对阵列内已分配且状态正常的休眠盘(HDD/SSD)并行发官方 emcmd cmdSpinup,
 * 全部提交后每秒重读一次 emhttp 状态文件确认起转,最多等 DISK_WAKE_WAIT_SEC 秒。
 * 命令未能提交才记 failures;等待超时未起转的盘留 pending(仍在起转),不算失败。
 */
function runDiskWake(): array
{
    set_time_limit(DISK_WAKE_TIME_LIMIT_SEC);

    // 前置条件与目标选取都在锁外做(纯读状态文件,无副作用)
    checkArrayReady();
    $sections = readDiskStateIni();
    if ($sections === null) {
        audit('diskwake', '-', 'fail');
        fail(503, '无法读取磁盘状态文件: ' . DISKS_INI_FILE);
    }
    $targets = diskWakeTargets($sections);
    if ($targets === []) {
        audit('diskwake', '-', 'ok');
        return [
            'targets' => [],
            'woken' => [],
            'pending' => [],
            'failures' => [],
            'timedOut' => false,
            'complete' => true,
        ];
    }

    // 互斥:同一时刻只允许一个唤醒流程在跑(flock 非阻塞,撞锁直接 409)
    $lock = @fopen(DISK_WAKE_LOCK_FILE, 'c');
    if ($lock === false) {
        audit('diskwake', '-', 'fail');
        fail(503, '无法创建唤醒锁文件: ' . DISK_WAKE_LOCK_FILE);
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        audit('diskwake', '-', 'fail');
        fail(409, '已有磁盘唤醒任务在执行,请稍后重试');
    }

    try {
        // 锁内按最新状态重选,避免等待锁期间其他客户端已改变盘态。
        $sections = readDiskStateIni();
        if ($sections === null) {
            audit('diskwake', '-', 'fail');
            fail(503, '无法读取磁盘状态文件: ' . DISKS_INI_FILE);
        }
        $targets = diskWakeTargets($sections);
        if ($targets === []) {
            audit('diskwake', '-', 'ok');
            return [
                'targets' => [], 'woken' => [], 'pending' => [], 'failures' => [],
                'timedOut' => false, 'complete' => true,
            ];
        }

        // 命令提交与状态等待共享 30 秒总预算。emcmd 在起转期间会阻塞,
        // 串行提交会把后面的盘挤出等待窗口(HDD 起转 10~20 秒,4 盘串行曾把
        // 后 2 块误报为失败),故所有目标盘并行后台下发,再统一等盘态。
        $deadline = time() + DISK_WAKE_WAIT_SEC;
        $failures = [];
        foreach ($targets as $target) {
            $name = $target['name'];
            $out = [];
            $code = 1;
            @exec(
                PATH_ENV . ' ' . EMCMD . ' cmdSpinup=' . escapeshellarg($name)
                . ' >/dev/null 2>&1 &',
                $out,
                $code
            );
            if ($code !== 0) {
                $failures[] = [
                    'name' => $name,
                    'device' => $target['device'],
                    'exitCode' => $code,
                    'error' => 'emcmd 提交失败',
                ];
            }
        }

        $spun = [];
        $woken = [];
        $pending = [];
        $timedOut = false;
        while (true) {
            $fresh = readDiskStateIni();
            if ($fresh !== null) {
                $spun = diskSpinState($fresh);
            }
            $woken = [];
            $pending = [];
            foreach ($targets as $target) {
                if (!empty($spun[$target['name']])) {
                    $woken[] = $target['name'];
                } else {
                    $pending[] = $target['name'];
                }
            }
            if ($pending === []) {
                break;
            }
            if (time() >= $deadline) {
                $timedOut = true;
                break;
            }
            sleep(1);
        }

        // 起转慢不等于失败:等待结束仍未起转的盘留在 pending(前端提示「仍在起转」),
        // 只有命令未能提交的才计为失败;emhttp 通常会在等待超时后继续完成唤醒。
        $detail = mb_substr(implode(',', array_column($targets, 'name')), 0, 64);
        audit('diskwake', $detail, $failures === [] ? 'ok' : 'fail');

        return [
            'targets' => array_column($targets, 'name'),
            'woken' => $woken,
            'pending' => $pending,
            'failures' => $failures,
            'timedOut' => $timedOut,
            'complete' => $pending === [] && $failures === [],
        ];
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

// ---------- 路由 ----------

/**
 * 【续 118/120/123】文件名搜索,三个 scope:
 * cache(默认):find /mnt/cache,只读 SSD 池,阵列休眠盘零接触;
 * all:find /mnt/user FUSE 联合视图,休眠盘会被唤醒(用户显式二次确认,audit 留痕);
 * index:grep 预建索引文件(INDEX_FILE,在 cache 上),覆盖全盘但零唤盘,
 *       结果新鲜度取决于索引构建时间(返回 indexTime 供前端展示)。
 * q 剥通配符/控制符后 escapeshellarg;timeout 防失控;多取 1 条判 truncated。
 * 索引/全盘行格式 '%P\t%y':相对 /mnt/user 路径 + 类型(d=目录),与前端深链约定一致。
 */
function searchFiles(string $q, string $scope = 'cache'): array
{
    if ($scope !== 'cache' && $scope !== 'all' && $scope !== 'index') {
        fail(400, '非法 scope(仅支持 cache/index/all)');
    }
    $q = str_replace(['*', '?', "\t", "\n", "\r"], '', trim($q));
    if (mb_strlen($q) < 2) {
        fail(400, '关键词至少 2 个字符');
    }

    if ($scope === 'index') {
        // 【续 130】不再查 index-enabled flag:搜索只读索引文件零唤盘,功能常开;
        // flag 只管每日自动构建。索引不存在 → 503,前端附「重建索引」
        if (!is_file(INDEX_FILE)) {
            fail(503, '索引不存在,请先重建索引');
        }
        $indexTime = (int) filemtime(INDEX_FILE);
        // -iF 定串忽略大小写;-e 防关键词以 - 开头被当选项
        $cmd = PATH_ENV . ' grep -iF -e ' . escapeshellarg($q)
            . ' ' . escapeshellarg(INDEX_FILE)
            . ' 2>/dev/null | head -n ' . (SEARCH_MAX_RESULTS + 1);
        $out = (string) @shell_exec($cmd);
        [$results, $truncated] = parseSearchLines($out);
        return [
            'results' => $results,
            'truncated' => $truncated,
            'root' => USER_SHARES_DIR,
            'scope' => $scope,
            'indexTime' => $indexTime,
        ];
    }

    $root = $scope === 'all' ? USER_SHARES_DIR : CACHE_POOL_DIR;
    $timeout = $scope === 'all' ? SEARCH_ALL_TIMEOUT_SEC : SEARCH_TIMEOUT_SEC;
    if (!is_dir($root)) {
        fail(503, '搜索根不存在: ' . $root);
    }
    if ($scope === 'all') {
        // 【续 124 修复】顶层软链份额(strm/appdata/isos 等 cache-only 份额在 /mnt/user 里是
        // -> ../cache/* 的软链),find 默认不跟随会整树漏掉;每个顶层软链单独一次 find -H
        // 跟随(只跟这层,不跟深层内层软链,防逃逸/环路);%p 打全路径再 sed 剥前缀
        $symlinkFinds = '';
        foreach (scandir($root) ?: [] as $e) {
            if ($e === '.' || $e === '..' || $e === '.trash') {
                continue;
            }
            $p = $root . '/' . $e;
            if (is_link($p)) {
                $symlinkFinds .= '; timeout ' . $timeout . ' find -H ' . escapeshellarg($p)
                    . ' -iname ' . escapeshellarg('*' . $q . '*')
                    . ' -printf ' . escapeshellarg("%p\t%y\n") . ' 2>/dev/null';
            }
        }
        $cmd = PATH_ENV . ' sh -c ' . escapeshellarg(
            '{ timeout ' . $timeout . ' find ' . escapeshellarg($root)
            . ' -path ' . escapeshellarg($root . '/.trash') . ' -prune -o -iname '
            . escapeshellarg('*' . $q . '*')
            . ' -printf ' . escapeshellarg("%p\t%y\n") . ' 2>/dev/null'
            . $symlinkFinds . '; } | sed ' . escapeshellarg('s|^' . $root . '/\?||')
        ) . ' | head -n ' . (SEARCH_MAX_RESULTS + 1);
    } else {
        $cmd = PATH_ENV . ' timeout ' . $timeout
            . ' find ' . escapeshellarg($root)
            . ' -path ' . escapeshellarg($root . '/.trash') . ' -prune -o -iname '
            . escapeshellarg('*' . $q . '*')
            . ' -printf ' . escapeshellarg("%P\t%y\n")
            . ' 2>/dev/null | head -n ' . (SEARCH_MAX_RESULTS + 1);
    }
    $out = (string) @shell_exec($cmd);
    [$results, $truncated] = parseSearchLines($out);
    return ['results' => $results, 'truncated' => $truncated, 'root' => $root, 'scope' => $scope];
}

/** 解析 '%P\t%y' 行 → results 数组 + truncated 标记(多取 1 条判截断) */
function parseSearchLines(string $out): array
{
    $results = [];
    foreach (explode("\n", trim($out)) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line);
        $path = $parts[0] ?? '';
        if ($path === '' || $path === '.' || $path === '.trash'
            || str_starts_with($path, '.trash/')) {
            continue;
        }
        $results[] = ['path' => $path, 'isDir' => ($parts[1] ?? '') === 'd'];
    }
    $truncated = count($results) > SEARCH_MAX_RESULTS;
    if ($truncated) {
        $results = array_slice($results, 0, SEARCH_MAX_RESULTS);
    }
    return [$results, $truncated];
}

/**
 * 【续 124】索引状态(设置页卡片用):开关/有无索引/构建时间/条数/大小。
 * 【续 130】enabled 语义 = 每日自动构建开(flag 只控 cron);索引搜索本身常开。
 * 条数与大小读 builder 写的 sidecar .meta(不扫 142MB 主文件);meta 缺失回退 filesize。
 */
/**
 * 索引构建锁是否有效:锁文件存在、其中 PID 对应的进程仍在、且确为构建脚本才算。
 * 构建异常中断(宿主重启/kill -9)会留下 stale 锁与空 tmp,只认文件存在会让
 * building 恒 true、App 永远「重建中…」;识别为 stale 时顺手清锁与本次遗留 tmp,
 * 状态自愈。只读 /proc 与 cache 池文件,不执行外部命令、不唤盘。
 */
function indexBuildLockValid(): bool
{
    $lockFile = dirname(INDEX_FILE) . '/.index-building';
    if (!is_file($lockFile)) {
        return false;
    }
    $pid = (int) trim((string) @file_get_contents($lockFile));
    if ($pid > 0 && is_dir('/proc/' . $pid)) {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        // cmdline 读不到时保守视为存活(不误清进行中的构建);读得到却不含脚本名 = PID 复用
        if ($cmdline === false || strpos((string) $cmdline, 'update-file-index.sh') !== false) {
            return true;
        }
    }
    @unlink($lockFile);
    if ($pid > 0) {
        @unlink(INDEX_FILE . '.tmp.' . $pid);
    }
    return false;
}

function indexStatus(): array
{
    $meta = [];
    $metaFile = INDEX_FILE . '.meta';
    if (is_file($metaFile)) {
        $decoded = json_decode((string) @file_get_contents($metaFile), true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    return [
        'enabled' => is_file(INDEX_ENABLED_FLAG),
        'hasIndex' => is_file(INDEX_FILE),
        'indexTime' => isset($meta['builtAt']) ? (int) $meta['builtAt']
            : (is_file(INDEX_FILE) ? (int) filemtime(INDEX_FILE) : null),
        'entries' => isset($meta['entries']) ? (int) $meta['entries'] : null,
        'sizeBytes' => is_file(INDEX_FILE) ? (int) filesize(INDEX_FILE) : null,
        'building' => indexBuildLockValid(),
        'hour' => indexHour(),
    ];
}

/** 【续 125】读定时索引整点(0-23,缺省 3;文件缺失/非法值回退 3) */
function indexHour(): int
{
    if (is_file(INDEX_HOUR_FILE)) {
        $h = trim((string) @file_get_contents(INDEX_HOUR_FILE));
        if (preg_match('/^\d{1,2}$/', $h) && (int) $h <= 23) {
            return (int) $h;
        }
    }
    return 3;
}

/** 注册/摘除索引 cron:优先调 register 脚本(install 脚本/go 钩子共用同一份逻辑);脚本缺失(旧安装)内联兜底 */
function refreshIndexCron(): void
{
    if (is_file(INDEX_CRON_REGISTER)) {
        @shell_exec(PATH_ENV . ' sh ' . escapeshellarg(INDEX_CRON_REGISTER) . ' >/dev/null 2>&1');
        return;
    }
    if (is_file(INDEX_ENABLED_FLAG)) {
        $line = '0 ' . indexHour() . ' * * * ' . INDEX_BUILDER . ' # unraid-mobile 文件索引';
        $cmd = '(crontab -l 2>/dev/null | grep -v "update-file-index\\.sh"; echo '
            . escapeshellarg($line) . ') | crontab -';
    } else {
        $cmd = 'crontab -l 2>/dev/null | grep -v "update-file-index\\.sh" | crontab -';
    }
    @shell_exec(PATH_ENV . ' sh -c ' . escapeshellarg($cmd));
}

/** 【续 124/125】索引配置:enabled = 每日自动构建开关(写/删 flag,【续 130】只管 cron)+ hour 定时整点(0-23);改完统一刷新 cron;enable 且无索引时后台补建 */
function setIndexConfig(array $body): array
{
    $hasEnabled = array_key_exists('enabled', $body);
    $hasHour = array_key_exists('hour', $body);
    if (!$hasEnabled && !$hasHour) {
        fail(400, 'enabled/hour 至少传一个');
    }
    $enabled = null;
    if ($hasEnabled) {
        $enabled = (string) $body['enabled'];
        if ($enabled !== 'true' && $enabled !== 'false') {
            fail(400, 'enabled 必须是 "true" 或 "false"');
        }
        if ($enabled === 'true') {
            if (@file_put_contents(INDEX_ENABLED_FLAG, date('c')) === false) {
                fail(500, 'flag 写入失败');
            }
        } else {
            @unlink(INDEX_ENABLED_FLAG);
        }
    }
    $hour = null;
    if ($hasHour) {
        $raw = (string) $body['hour'];
        if (!preg_match('/^\d{1,2}$/', $raw) || (int) $raw > 23) {
            fail(400, 'hour 必须是 0-23 的整数');
        }
        $hour = (int) $raw;
        if (@file_put_contents(INDEX_HOUR_FILE, (string) $hour) === false) {
            fail(500, 'hour 写入失败');
        }
    }
    refreshIndexCron();
    // 启用时还没有索引 → 后台补建一次(会唤盘,audit 在 reindex 语义里已覆盖)
    if ($enabled === 'true' && !is_file(INDEX_FILE) && is_file(INDEX_BUILDER)) {
        exec(PATH_ENV . ' nohup sh ' . escapeshellarg(INDEX_BUILDER) . ' > /dev/null 2>&1 &');
    }
    $detail = trim(($enabled !== null ? "enabled=$enabled " : '') . ($hour !== null ? "hour=$hour" : ''));
    audit('indexcfg', $detail, 'ok');
    return indexStatus();
}

/**
 * 服务器电源(重启/关机)——高危不可逆操作。
 *
 * 语义(与本文件其余端点不同,逐条都是硬约束):
 *   1. 严格白名单:operation 只接受 'reboot' / 'shutdown';请求体除 action/operation 外
 *      出现任何额外键(延迟秒数、强制标志、命令…)一律 400 拒绝,防止"顺手带个参数"绕过白名单。
 *   2. 用户输入不进 shell:执行命令全部来自 SERVER_POWER_COMMANDS 常量表,用户输入只当表键查表,
 *      查不到就拒绝;代码里不存在把 $body 拼进命令行的路径。
 *   3. 审计先写:命令一旦下发,机器可能在秒级断电,进程与内存会一起消失,事后无从追溯 ——
 *      所以先把意图写进 flash 审计日志,再下发命令。拒绝的请求同样留痕。
 *   4. 异步下发:nohup 后台执行官方 WebGUI 同款命令,不等它跑完、不等断线,立即返回
 *      {accepted:true, operation},由前端提示"服务器即将重启/关机"。
 */
function runServerPower(array $body): void
{
    // (1) 只接受 action / operation 两个键,其余一律拒绝(不回显用户输入,避免日志/响应注入)
    foreach (array_keys($body) as $key) {
        if ($key !== 'action' && $key !== 'operation') {
            fail(400, 'serverpower 只接受 action 与 operation 两个参数');
        }
    }
    // (2) operation 严格白名单;表里没有就是拒绝(不做任何"猜测式"纠正)
    //     非字符串(数组/对象/数字)一律按非法处理,先归一成字符串再判定,避免后续处理抛 TypeError
    $rawOperation = $body['operation'] ?? '';
    $operation = is_string($rawOperation) ? $rawOperation : '';
    if ($operation === '' || !array_key_exists($operation, SERVER_POWER_COMMANDS)) {
        // 审计只记净化后的标签(剥掉控制字符/换行,防伪造审计行),不回显原始输入
        $label = preg_replace('/[^A-Za-z0-9_-]/', '', mb_substr($operation, 0, 32));
        audit('serverpower', is_string($label) && $label !== '' ? $label : '-', 'fail');
        fail(400, 'operation 必须是 "reboot" 或 "shutdown"');
    }
    // (3) 审计先行 —— 命令下发后机器可能秒级断电,先留痕再执行
    audit('serverpower', $operation, 'ok');
    // (4) 异步下发:命令取自固定表(用户输入只做查表),nohup 后台跑,立即返回不等断线
    exec(PATH_ENV . ' nohup ' . SERVER_POWER_COMMANDS[$operation] . ' > /dev/null 2>&1 &');
    ok(['accepted' => true, 'operation' => $operation]);
}

/**
 * 宿主能力自检:只读、不执行外部命令(docker/emcmd 只查文件存在性)、不联网。
 * 用途:Unraid OS 升级后,前端按 deps 降级对应功能,而不是运行时报错;
 * apiSha256 与 bootApiSha256 不一致说明执行位置与正本不同步(可能恢复成旧版)。
 */
function readCapabilities(): array
{
    $bootDir = '/boot/config/plugins/unraid-mobile';
    $unraidVersion = '';
    if (is_file('/etc/unraid-version')) {
        $ini = @parse_ini_file('/etc/unraid-version');
        $unraidVersion = trim((string) ($ini['version'] ?? ''));
    }

    // go 恢复钩子落盘的错误日志:存在即上次启动恢复有异常;截断回传,避免异常大文件撑爆响应
    $restoreError = null;
    $restoreLog = $bootDir . '/restore-error.log';
    if (is_file($restoreLog)) {
        $raw = trim((string) @file_get_contents($restoreLog));
        if ($raw !== '') {
            $restoreError = strlen($raw) > 2000 ? substr($raw, 0, 2000) . '…' : $raw;
        }
    }

    $sha = static function (string $file): ?string {
        if (!is_file($file)) {
            return null;
        }
        $hash = @hash_file('sha256', $file);
        return is_string($hash) && $hash !== '' ? $hash : null;
    };

    return [
        'appVersion' => APP_VERSION,
        'hostUpdateSupported' => true,
        'unraidVersion' => $unraidVersion,
        'phpVersion' => PHP_VERSION,
        'apiSha256' => $sha(__FILE__),
        'bootApiSha256' => $sha($bootDir . '/api.php'),
        'restoreError' => $restoreError,
        'deps' => [
            'templateUserDir' => is_dir(TEMPLATE_USER_DIR) && is_writable(TEMPLATE_USER_DIR),
            'updateContainerScript' => is_file(DOCKER_UPDATE_SCRIPT),
            'updateContainerSetsid' => is_executable(DOCKER_UPDATE_SETSID),
            'dockerEditHelper' => is_file(DOCKER_EDIT_HELPER),
            'dockerCli' => is_executable(DOCKER),
            'emcmd' => is_executable(EMCMD),
            'pluginCli' => is_executable('/usr/local/sbin/plugin'),
            'pluginWorker' => is_file(__DIR__ . '/plugin-worker.php'),
            'updateStatusWriter' => is_file(__DIR__ . '/update-status.php'),
            'updateStatusCache' => is_file(UPDATE_STATUS_FILE),
            'indexBuilder' => is_file(INDEX_BUILDER),
            'disksIni' => is_file(DISKS_INI_FILE),
            'varIni' => is_file(ARRAY_VAR_FILE),
        ],
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');
    requireProAction($action, 'GET');

    // 宿主能力自检:只读、不依赖 PROJECTS_DIR,放在最前
    if ($action === 'capabilities') {
        ok(readCapabilities());
    }

    // 【续 51】CPU 温度(sysfs,不唤盘),放在栈相关 action 之前(不需要 PROJECTS_DIR)
    if ($action === 'cputemp') {
        ok(readCpuTemp());
    }

    if ($action === 'networkinfo') {
        ok(readNetworkInfo());
    }

    if ($action === 'systemmetrics') {
        ok(readSystemMetrics());
    }

    if ($action === 'vminfo') {
        $vm = (string) ($_GET['vm'] ?? '');
        if (!isVmUuid($vm) && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $vm)) {
            fail(400, '非法 VM UUID 或名称');
        }
        ok(readVmInfo($vm));
    }

    if ($action === 'vmstats') {
        $vm = (string) ($_GET['vm'] ?? '');
        if (!isVmUuid($vm)) {
            fail(400, '非法 VM UUID');
        }
        ok(readVmStats($vm));
    }

    if ($action === 'vmlog') {
        $vm = (string) ($_GET['vm'] ?? '');
        $offset = filter_var($_GET['offset'] ?? -1, FILTER_VALIDATE_INT);
        if (!isVmUuid($vm)) {
            fail(400, '非法 VM UUID');
        }
        if ($offset === false || $offset < -1) {
            fail(400, '非法日志游标');
        }
        ok(readVmLog($vm, $offset));
    }

    if ($action === 'plugins') {
        ok(listPlugins());
    }

    if ($action === 'pluginjob') {
        $id = (string) ($_GET['id'] ?? '');
        $job = readPluginJob($id);
        if ($job === null) {
            fail(404, '插件任务不存在');
        }
        ok($job);
    }

    // CA 应用商店(只读):官方 feed → 白名单归一化 → /tmp 缓存;refresh=1 强制重取
    if ($action === 'caapps') {
        $refresh = in_array((string) ($_GET['refresh'] ?? '0'), ['1', 'true', 'yes'], true);
        ok(caAppsResponse($refresh));
    }

    if ($action === 'catemplate') {
        $url = caTemplateUrl($_GET['url'] ?? null);
        ok(caTemplateData($url));
    }

    if ($action === 'cajob') {
        $id = (string) ($_GET['id'] ?? '');
        $job = readCaJob($id);
        if ($job === null) {
            fail(404, 'CA 安装任务不存在');
        }
        ok($job);
    }

    if ($action === 'dockertemplate') {
        ok(dockerEditData(dockerEditName($_GET['name'] ?? null)));
    }

    if ($action === 'dockereditjob') {
        $id = (string) ($_GET['id'] ?? '');
        $job = readDockerEditJob($id);
        if ($job === null) {
            fail(404, '参数编辑任务不存在');
        }
        ok($job);
    }

    // 【续 112】有更新的镜像 ref 列表(直读更新缓存,剥 library/ 前缀;纯文件读,不唤盘)
    if ($action === 'updates') {
        ok(readImageUpdates());
    }

    if ($action === 'iconfile') {
        sendContainerIconFile($_GET['id'] ?? null);
    }

    if ($action === 'containericons') {
        $icons = dockerContainerIcons();
        if ($icons === null) {
            fail(503, '无法读取 Docker 容器图标');
        }
        ok($icons);
    }

    // 【续 118/120】文件搜索(显式触发):scope=cache 只读 /mnt/cache 不唤盘;
    // scope=all 扫 /mnt/user 唤醒休眠盘(前端二次确认),audit 留痕
    if ($action === 'search') {
        $scope = (string) ($_GET['scope'] ?? 'cache');
        // scope=all 是免费版的显式全盘搜索;Pro 的 cache/index 快速搜索需要后端授权。
        if ($scope !== 'all') {
            requireProLicense();
        }
        if ($scope === 'all') {
            audit('search-all', mb_substr((string) ($_GET['q'] ?? ''), 0, 64), 'ok');
        }
        ok(searchFiles((string) ($_GET['q'] ?? ''), $scope));
    }

    // 【续 124】索引状态(设置页卡片;只读 flag/meta/文件大小,不唤盘)
    if ($action === 'indexstatus') {
        ok(indexStatus());
    }

    if ($action === 'externalprojects') {
        ok(externalProjects());
    }

    if ($action === 'list') {
        $names = [];
        foreach (scandir(PROJECTS_DIR) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir(PROJECTS_DIR . '/' . $entry)) {
                $names[] = $entry;
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $lsMap = composeLsMap();
        $serviceMap = [];
        $pcMap = projectContainersMap($serviceMap);
        $iconMap = dockerContainerIconMap(dockerContainerIcons());
        ok(array_map(fn($n) => stackSummary($n, $lsMap, $pcMap, $iconMap, $serviceMap), $names));
    }

    if ($action === 'get') {
        $name = validName($_GET['name'] ?? '');
        $dir = PROJECTS_DIR . '/' . $name;
        $composeFile = firstExisting($dir, composeFileCandidates());
        $overrideFile = firstExisting($dir, overrideFileCandidates());
        $serviceMap = [];
        $pcMap = projectContainersMap($serviceMap);
        $iconMap = dockerContainerIconMap(dockerContainerIcons());
        ok([
            'stack' => stackSummary($name, composeLsMap(), $pcMap, $iconMap, $serviceMap),
            'composeYaml' => $composeFile !== null ? (string) file_get_contents($dir . '/' . $composeFile) : '',
            'overrideYaml' => $overrideFile !== null ? (string) file_get_contents($dir . '/' . $overrideFile) : null,
            'lastCmdLog' => tailFile($dir . '/last_cmd.log'),
            'opRunning' => file_exists($dir . '/.op-running'),
        ]);
    }

    if ($action === 'log') {
        $name = validName($_GET['name'] ?? '');
        $dir = PROJECTS_DIR . '/' . $name;
        ok([
            'log' => tailFile($dir . '/last_cmd.log'),
            'running' => file_exists($dir . '/.op-running'),
        ]);
    }

    if ($action === 'externalupdatelog') {
        ok(externalUpdateLog($_GET['requestId'] ?? null, $_GET['name'] ?? null, $_GET['project'] ?? null));
    }

    if ($action === 'hostupdatelog') {
        if (!is_dir(HOST_UPDATE_DIR)) fail(404, '无宿主后端升级记录');
        $rawRequestId = $_GET['requestId'] ?? null;
        $requestId = $rawRequestId !== null ? dockerUpdateRequestId($rawRequestId) : null;
        $status = readHostUpdateStatus();
        if ($requestId !== null && (!is_string($status['requestId'] ?? null)
            || !hash_equals($requestId, $status['requestId']))) {
            fail(409, '当前宿主升级任务与原请求不匹配,请刷新状态后重试');
        }
        ok([
            'jobId' => $status['jobId'],
            'requestId' => $status['requestId'],
            'targetVersion' => $status['targetVersion'],
            'state' => $status['state'],
            'message' => $status['message'],
            'log' => tailFile(HOST_UPDATE_DIR . '/update.log'),
            'running' => $status['running'],
            'result' => $status['result'],
        ]);
    }

    // 更新状态由任务记录和真实进程组推导;旧字段 running/log/result 保持兼容。
    if ($action === 'dockerlog') {
        $name = (string) ($_GET['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $name)) {
            fail(400, '非法容器名');
        }
        $dir = DOCKER_UPDATE_DIR . '/' . $name;
        if (!is_dir($dir)) {
            fail(404, '无更新记录: ' . $name);
        }
        $rawRequestId = $_GET['requestId'] ?? null;
        $requestId = $rawRequestId !== null ? dockerUpdateRequestId($rawRequestId) : null;
        $status = readDockerUpdateStatus($name);
        if ($requestId !== null && (!is_string($status['requestId'] ?? null)
            || !hash_equals($requestId, $status['requestId']))) {
            fail(409, '当前更新任务与原请求不匹配,请刷新状态后重试');
        }
        ok([
            'jobId' => $status['jobId'],
            'requestId' => $status['requestId'],
            'state' => $status['state'],
            'message' => $status['message'],
            'log' => tailFile($dir . '/update.log'),
            'running' => $status['running'],
            'result' => $status['result'],
        ]);
    }

    fail(400, '未知 action: ' . $action);
}

if ($method === 'POST') {
    // 【排障 2026-07-19】POST 走不通:php.ini auto_prepend_file=local_prepend.php
    // 对 POST 强制 CSRF 校验(除 /login.php /auth-request.php),无 token 静默 exit(空 200)。
    // 该 prepend 是 webGui 的系统文件不能改,故本 API 的写操作全部走 PUT(不受 CSRF 检查),
    // 由本文件自有的 X-Api-Key 鉴权兜底。POST 保留此分支仅作显式报错。
    fail(405, 'POST 不受支持(webGui CSRF prepend 拦截),请用 PUT');
}

// 写操作统一走 PUT:
//   Content-Type: application/json → JSON 指令(action 分发)
//   其他 Content-Type → 视为 compose.yaml 原文保存
if ($method === 'PUT' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        fail(400, '请求体必须是 JSON');
    }
    $action = (string) ($body['action'] ?? '');

    // 激活时同步本机后端凭证;之后 Pro 路由离线验签 + 核对本机哈希。
    if ($action === 'licenseactivate') {
        requireProLicense();
        ok(['active' => true]);
    }
    requireProAction($action, 'PUT');

    if ($action === 'plugincheck') {
        $jobId = startPluginJob('plugincheck');
        ok(['jobId' => $jobId, 'async' => true]);
    }

    if ($action === 'pluginupdate' || $action === 'pluginremove') {
        $name = pluginId($body['name'] ?? '');
        if (!pluginInstalled($name)) {
            fail(404, '插件未安装: ' . $name);
        }
        if ($action === 'pluginremove' && !pluginManaged($name)) {
            fail(403, '该插件不允许移除');
        }
        if ($action === 'pluginupdate' && operatingSystemPlugin($name)) {
            fail(403, 'Unraid OS 更新不属于插件管理');
        }
        $jobId = startPluginJob($action, [$name]);
        ok(['jobId' => $jobId, 'async' => true]);
    }

    if ($action === 'pluginupdateall') {
        $jobId = startPluginJob('pluginupdateall');
        ok(['jobId' => $jobId, 'async' => true]);
    }

    if ($action === 'plugininstall') {
        [$url, $safeUrl, $name] = validatePluginInstallUrl($body['url'] ?? '');
        $jobId = startPluginJob('plugininstall', [$name], $url, $safeUrl);
        ok(['jobId' => $jobId, 'async' => true]);
    }

    if ($action === 'cainstall') {
        $jobId = startCaInstall($body);
        ok(['jobId' => $jobId, 'async' => true]);
    }

    if ($action === 'dockeredit') {
        $jobId = startDockerEdit($body);
        ok(['jobId' => $jobId, 'async' => true]);
    }

    if ($action === 'seticon') {
        ok(setContainerIcon($body));
    }

    if ($action === 'autostart') {
        $name = validName($body['name'] ?? '');
        $value = (string) ($body['value'] ?? '');
        if ($value !== 'true' && $value !== 'false') {
            fail(400, 'value 必须是 "true" 或 "false"');
        }
        $dir = PROJECTS_DIR . '/' . $name;
        if (file_put_contents($dir . '/autostart', $value) === false) {
            audit('autostart', $name, 'fail');
            fail(500, 'autostart 写入失败');
        }
        audit('autostart', $name, 'ok');
        ok(['autostart' => $value === 'true']);
    }

    // 【续 124/125】索引配置(设置页;enabled 开关 / hour 定时整点,audit 留痕)
    if ($action === 'indexcfg') {
        ok(setIndexConfig($body));
    }

    // 【续 123】重建全盘文件索引(后台跑,find /mnt/user 会唤盘 → 显式触发 + audit 留痕)
    if ($action === 'reindex') {
        if (!is_file(INDEX_BUILDER)) {
            fail(503, '索引构建脚本未安装: ' . INDEX_BUILDER . '(重跑 install-compose-api.sh)');
        }
        exec(PATH_ENV . ' nohup sh ' . escapeshellarg(INDEX_BUILDER) . ' > /dev/null 2>&1 &');
        audit('reindex', '-', 'ok');
        ok(['async' => true]);
    }

    // 磁盘唤醒(显式触发):只唤醒已确认休眠的阵列磁盘(HDD/SSD),起转走官方 emcmd
    if ($action === 'diskwake') {
        ok(runDiskWake());
    }

    // 普通 Docker 更新:异步任务、幂等恢复、容器身份与 Compose 归属复核。
    if ($action === 'dockerupdate') {
        $name = (string) ($body['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $name)) {
            fail(400, '非法容器名');
        }
        $expectedId = dockerUpdateExpectedId($body['expectedId'] ?? null);
        $requestId = dockerUpdateRequestId($body['requestId'] ?? null);
        $dir = DOCKER_UPDATE_DIR . '/' . $name;
        if (is_dir($dir)) {
            $existing = readDockerUpdateStatus($name);
            if (is_string($existing['requestId'] ?? null) && hash_equals($requestId, $existing['requestId'])) {
                $raw = readJsonFile($dir . '/status.json');
                $recordedExpected = strtolower((string) ($raw['expectedId'] ?? ''));
                if ($expectedId !== null && $recordedExpected !== ''
                    && !hash_equals($expectedId, $recordedExpected)) {
                    fail(409, 'requestId 已用于另一个容器身份,不能重复提交');
                }
                ok([
                    'async' => true,
                    'jobId' => $existing['jobId'],
                    'requestId' => $existing['requestId'],
                    'duplicate' => true,
                ]);
            }
        }
        $container = dockerUpdateContainer($name, $expectedId);
        // update_container 无模板会静默跳过(continue),提前 422 报清。
        $tmpl = findUserTemplate($name);
        if ($tmpl === null) {
            fail(422, '该容器无 webGui 模板,无法用此方式更新(可走 Compose/命令行)');
        }
        ok(runDockerUpdateAsync($name, $tmpl, $container, $expectedId, $requestId));
    }

    if ($action === 'externalupdate') {
        ok(startExternalUpdate($body));
    }

    if ($action === 'hostupdate') {
        $targetVersion = hostUpdateTargetVersion($body['targetVersion'] ?? null);
        $requestId = dockerUpdateRequestId($body['requestId'] ?? null);
        ok(runHostUpdateAsync($targetVersion, $requestId));
    }

    // 服务器电源(重启/关机):高危不可逆。严格白名单 + 审计先写 + nohup 异步下发,
    // 立即返回 {accepted:true,operation},不等待断线(详见 runServerPower 注释)。
    if ($action === 'serverpower') {
        runServerPower($body);
    }

    // 固定子命令白名单,参数不外传(无注入面)
    $syncOps = ['up' => 'up -d', 'down' => 'down', 'restart' => 'restart'];
    $asyncOps = ['pull' => 'pull', 'rebuild' => 'up -d --build --force-recreate'];

    if (isset($syncOps[$action])) {
        $name = validName($body['name'] ?? '');
        $dir = PROJECTS_DIR . '/' . $name;
        [$exitCode, $output] = runComposeSync($dir, $action, $syncOps[$action]);
        // 【续 50 D3-3】同步动作按退出码记成败
        audit($action, $name, $exitCode === 0 ? 'ok' : 'fail');
        ok([
            'exitCode' => $exitCode,
            'output' => $output,
            'running' => composeLsMap()[projectName($name)]['Status'] ?? null,
        ]);
    }

    if (isset($asyncOps[$action])) {
        $name = validName($body['name'] ?? '');
        runComposeAsync(PROJECTS_DIR . '/' . $name, $action, $asyncOps[$action]);
        ok(['async' => true]);
    }

    fail(400, '未知 action: ' . $action);
}

if ($method === 'PUT') {
    // 非 JSON PUT 是 Compose YAML 保存,同样属于 Pro 写操作。
    requireProLicense();
    $name = validName($_GET['name'] ?? '');
    $dir = PROJECTS_DIR . '/' . $name;
    $yaml = (string) file_get_contents('php://input');
    if (trim($yaml) === '' || !str_contains($yaml, 'services:')) {
        fail(400, 'compose.yaml 内容无效(为空或缺少 services:)');
    }

    $composeFile = firstExisting($dir, composeFileCandidates()) ?? 'compose.yaml';
    $target = $dir . '/' . $composeFile;
    $backup = $target . '.unraid-mobile.bak';
    if (is_file($target) && @copy($target, $backup) === false) {
        audit('yaml-write', $name, 'fail');
        fail(500, '备份原文件失败,已中止写入');
    }
    // 【续 50 D3-1】原子写入:先写同目录 .tmp → 校验 tmp → rename 原子替换;
    // 校验失败删 tmp,原文件全程不被触碰(不再有读到半截坏文件的窗口),bak 保留作双保险
    $tmp = $target . '.tmp';
    if (file_put_contents($tmp, $yaml) === false) {
        audit('yaml-write', $name, 'fail');
        fail(500, 'compose.yaml 临时文件写入失败');
    }

    // 校验 tmp:显式 -f 指向 tmp 文件(有 override 时一并带上,与默认合并行为一致)
    set_time_limit(60);
    $checkArgs = '-f ' . escapeshellarg($composeFile . '.tmp');
    $overrideFile = firstExisting($dir, overrideFileCandidates());
    if ($overrideFile !== null) {
        $checkArgs .= ' -f ' . escapeshellarg($overrideFile);
    }
    $checkCmd = sprintf(
        'cd %s && %s %s compose %s config -q 2>&1',
        escapeshellarg($dir),
        PATH_ENV,
        DOCKER,
        $checkArgs
    );
    $checkOut = [];
    $checkCode = 1;
    exec($checkCmd, $checkOut, $checkCode);
    if ($checkCode !== 0) {
        @unlink($tmp);
        audit('yaml-write', $name, 'fail');
        fail(422, 'compose 校验失败,原文件未改动: ' . implode('; ', array_slice($checkOut, 0, 5)));
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        audit('yaml-write', $name, 'fail');
        fail(500, 'compose.yaml 原子替换失败(原文件未改动)');
    }
    @unlink($backup);
    audit('yaml-write', $name, 'ok');
    ok(['saved' => true, 'file' => $composeFile]);
}

fail(405, 'Method Not Allowed');
