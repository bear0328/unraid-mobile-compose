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
 *       【续 60】文件格式 `sha256:<hex>` = 存 key 的哈希,不明文存 key —— flash 备份/诊断包
 *       外泄不泄 key 本身;无前缀的旧明文格式自动兼容(重跑安装脚本即升级为哈希)。
 *
 * 端点:
 *   GET  ?action=cputemp                    CPU 温度(续 51:直读 /sys/class/hwmon CPU 传感器,
 *                                           纯内核 sysfs 不碰 smartctl/块设备,全盘 standby 不唤盘。
 *                                           这是续 46.5 "GraphQL temperature 唤盘红线"的安全替代)
 *   GET  ?action=networkinfo                网卡状态与累积字节(直读 /proc/net/dev + /sys/class/net,
 *                                           不执行外部命令,计数字符串防 64 位精度损失)
 *   GET  ?action=vminfo&vm=UUID             VM 静态配置(libvirt XML,旧名称寻址兼容)
 *   GET  ?action=vmstats&vm=UUID            VM CPU/内存/磁盘/网络原始计数器(domstats)
 *   GET  ?action=vmlog&vm=UUID&offset=N     QEMU/libvirt 日志字节游标增量读取(固定目录)
 *   GET  ?action=updates                    有更新的镜像 ref 列表(合并 webGui 主缓存与
 *                                           Compose 补充缓存,剥 library/ 前缀;补充缓存
 *                                           过期时后台补扫,请求不同步等待 Registry)
 *   GET  ?action=plugins                    已安装插件与本地检查缓存(不联网)
 *   GET  ?action=pluginjob&id=X             插件异步任务状态/进度/截尾日志
 *   PUT  {action: plugincheck|pluginupdateall}
 *   PUT  {action: pluginupdate|pluginremove, name}
 *   PUT  {action: plugininstall, url}       均返回 jobId,由独立 worker 串行调用官方 plugin CLI
 *   GET  ?action=list                       栈列表(名称/状态/autostart/last_result)
 *   GET  ?action=get&name=X                 栈详情(compose.yaml/override/log)
 *   GET  ?action=log&name=X                 操作日志 + 是否有异步任务在跑
 *   PUT  {action: up|down|restart, name}    同步执行(快操作) — Content-Type: application/json
 *   PUT  {action: pull|rebuild, name}       异步执行(慢操作,前端轮询 log)
 *   PUT  {action: autostart, name, value}   value = "true"|"false"
 *   PUT  {action: diskwake}               显式唤醒已确认休眠的阵列数据 HDD(判定只读 emhttp
 *                                           状态文件,起转走官方 emcmd cmdSpinup;不用 SMART、不读块设备)
 *   PUT  ?name=X   body=compose.yaml 内容    tmp+校验+rename 原子写入,失败原文件不动(留 bak 双保险)
 *
 * 注意: 写操作必须用 PUT 不能用 POST — php.ini auto_prepend_file(local_prepend.php)
 *       对所有 POST 强制 webGui CSRF 校验,无 token 静默 exit(空 200);PUT 不受检查。
 */

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
const TEMPLATE_USER_DIR = '/boot/config/plugins/dockerMan/templates-user';
const PLUGIN_WORK_DIR = '/tmp/unraid-mobile-plugins';
const PLUGIN_WORKER = '/usr/local/emhttp/plugins/compose.manager/plugin-worker.php';
const PLUGIN_INSTALLED_DIR = '/var/log/plugins';

// 磁盘唤醒(显式触发):判定只读 emhttp 状态文件,不跑 SMART/hdparm/dd/mdcmd、不读块设备,
// 所以「读状态」这一步本身不会唤醒任何盘;只有用户显式调用才发起起转。
const DISKS_INI_FILE = '/usr/local/emhttp/state/disks.ini';
const ARRAY_VAR_FILE = '/var/local/emhttp/var.ini';
const DISK_WAKE_LOCK_FILE = '/tmp/unraid-mobile-diskwake.lock';
const EMCMD = '/usr/local/sbin/emcmd';
// 单条 emcmd 的兜底超时(emhttp 忙时不让整个请求挂死)
const DISK_WAKE_CMD_TIMEOUT_SEC = 5;
// 全部提交后最多等 30s(每秒重读一次状态文件),整体请求上限 45s
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
function projectContainersMap(): array
{
    $out = [];
    exec(
        PATH_ENV . ' ' . DOCKER
        . ' ps -a --filter label=com.docker.compose.project'
        . " --format '{{.Names}}|{{.Label \"com.docker.compose.project\"}}' 2>/dev/null",
        $out
    );
    $map = [];
    foreach ($out as $line) {
        $parts = explode('|', trim($line), 2);
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $map[$parts[1]][] = $parts[0];
        }
    }
    return $map;
}

function readJsonFile(string $path): ?array
{
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : null;
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

function stackSummary(string $dirName, array $lsMap, array $pcMap = []): array
{

    $dir = PROJECTS_DIR . '/' . $dirName;
    $project = projectName($dirName);
    $row = $lsMap[$project] ?? null;
    $status = $row['Status'] ?? null; // 例: "running(1)" / "exited(0)"
    return [
        'name' => $dirName,
        'project' => $project,
        'status' => $status,
        'running' => is_string($status) && str_starts_with($status, 'running'),
        'autostart' => readAutostart($dir),
        'lastResult' => readJsonFile($dir . '/last_result.json'),
        'composeFile' => firstExisting($dir, composeFileCandidates()),
        // 【续 70】该栈的容器名列表(label 精确归属,自定义 container_name 也准)
        'containers' => $pcMap[$project] ?? [],
    ];
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
function dockerUpdateJobScript(string $name, string $tmpl, string $dir, string $ip): string
{
    return '#!/bin/bash' . "\n"
        . 'cd ' . escapeshellarg($dir) . ' || exit 1' . "\n"
        . 'exec > update.log 2>&1' . "\n"
        . 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin' . "\n"
        . 'name=' . escapeshellarg($name) . "\n"
        . 'read -r oldcid oldiid img < <(/usr/bin/docker inspect "$name" --format \'{{.Id}} {{.Image}} {{.Config.Image}}\')' . "\n"
        . 'echo "[$(date +%H:%M:%S)] 更新容器 $name"' . "\n"
        . 'echo "镜像: $img"' . "\n"
        . 'trep=$(sed -n \'s:.*<Repository>\\(.*\\)</Repository>.*:\1:p\' ' . escapeshellarg($tmpl) . ' | head -1 | tr -d \'[:space:]\')' . "\n"
        . 'pulls="$img"; [ -n "$trep" ] && [ "$trep" != "$img" ] && pulls="$img $trep"' . "\n"
        . 'ec=0' . "\n"
        . 'for ref in $pulls; do' . "\n"
        . '  echo; echo "== 拉取镜像 $ref =="' . "\n"
        . '  /usr/bin/docker pull "$ref" || { ec=$?; break; }' . "\n"
        . 'done' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  echo; echo "== 重建容器 =="' . "\n"
        . '  ' . escapeshellarg(DOCKER_UPDATE_SCRIPT) . ' "$name"; uec=$?' . "\n"
        . '  echo "(update_container exit $uec)"; [ "$uec" -ne 0 ] && ec=$uec' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  read -r newcid newiid < <(/usr/bin/docker inspect "$name" --format \'{{.Id}} {{.Image}}\' 2>/dev/null)' . "\n"
        . '  if [ "$newcid" = "$oldcid" ] && [ "$newiid" = "$oldiid" ]; then' . "\n"
        . '    echo; echo "!! 未检测到更新动作(容器/镜像 ID 均未变)"; ec=28' . "\n"
        . '  fi' . "\n"
        . 'fi' . "\n"
        . 'if [ "$ec" -eq 0 ]; then' . "\n"
        . '  echo; echo "== 更新完成 =="' . "\n"
        . '  /usr/bin/php ' . escapeshellarg(__DIR__ . '/update-status.php') . ' --image "$img" >/dev/null 2>&1' . "\n"
        . 'fi' . "\n"
        . 'printf \'{"result":"%s","exit_code":%d,"operation":"dockerupdate","timestamp":"%s"}\' '
        . '"$([ "$ec" -eq 0 ] && echo success || echo error)" "$ec" "$(date +%Y-%m-%dT%H:%M:%S%z)" > result.json' . "\n"
        . 'printf \'[%s] action=dockerupdate stack=%s result=%s ip=%s\n\' "$(date -Iseconds)" "$name" '
        . '"$([ "$ec" -eq 0 ] && echo ok || echo fail)" ' . escapeshellarg($ip) . ' >> ' . escapeshellarg(AUDIT_LOG) . "\n"
        . 'rm -f op-lock' . "\n";
}

/** 【续 147】op-lock(fopen x 原子,撞锁 409;tmpfs 重启自清,无 compose 的 flash 残留锁问题)+ 落 job.sh + nohup */
function runDockerUpdateAsync(string $name, string $tmpl): void
{
    $dir = DOCKER_UPDATE_DIR . '/' . $name;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        fail(500, '工作目录创建失败');
    }
    // 上一轮 result 先清,否则前端轮询会读到旧结果
    @unlink($dir . '/result.json');
    $lockFile = $dir . '/op-lock';
    $lock = @fopen($lockFile, 'x');
    if ($lock === false) {
        audit('dockerupdate', $name, 'fail');
        fail(409, '该容器有更新正在进行中,请等待完成');
    }
    fwrite($lock, 'dockerupdate');
    fclose($lock);
    $job = $dir . '/job.sh';
    if (file_put_contents($job, dockerUpdateJobScript($name, $tmpl, $dir, (string) ($_SERVER['REMOTE_ADDR'] ?? '-'))) === false) {
        @unlink($lockFile);
        fail(500, '任务脚本写入失败');
    }
    chmod($job, 0700);
    exec(PATH_ENV . ' nohup bash ' . escapeshellarg($job) . ' > /dev/null 2>&1 &');
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
            'status' => $status,
            'statusMessage' => is_string($saved['statusMessage'] ?? null) ? $saved['statusMessage'] : null,
            'managed' => pluginManaged($id),
        ];
    }
    usort($plugins, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return ['plugins' => $plugins, 'checkedAt' => $cache['checkedAt'], 'activeJob' => $active];
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
 * 选目标盘:必须同时满足 —— 阵列数据盘(type=Data)、机械盘(rotational=1)、
 * 当前已停转(spundown=1)、状态 DISK_OK、有设备名(device 非空)、
 * 盘名形如 diskN(N≥1)。parity/cache/flash/正在转的盘一律排除。
 */
function diskWakeTargets(array $sections): array
{
    $targets = [];
    foreach ($sections as $section => $slot) {
        $name = trim((string) ($slot['name'] ?? $section));
        if (!preg_match('/^disk[1-9][0-9]*$/', $name)) {
            continue;
        }
        if (strcasecmp(trim((string) ($slot['type'] ?? '')), 'Data') !== 0) {
            continue;
        }
        if (!in_array(strtolower(trim((string) ($slot['rotational'] ?? ''))), ['1', 'true'], true)) {
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
 * 磁盘唤醒:只对「已确认休眠」的阵列数据机械盘发官方 emcmd cmdSpinup,
 * 全部提交后每秒重读一次 emhttp 状态文件确认起转,最多等 DISK_WAKE_WAIT_SEC 秒。
 * 单盘命令失败记进 failures 后继续;等待超时也返回结构化结果(前端继续刷新)。
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

        // 命令提交与状态等待共享 30 秒总预算,多盘慢响应也不能突破客户端超时。
        $deadline = time() + DISK_WAKE_WAIT_SEC;
        $failures = [];
        foreach ($targets as $target) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                break;
            }
            $name = $target['name'];
            $out = [];
            $code = 1;
            $commandTimeout = min(DISK_WAKE_CMD_TIMEOUT_SEC, max(1, $remaining));
            @exec(
                PATH_ENV . ' timeout ' . $commandTimeout . ' ' . EMCMD
                . ' cmdSpinup=' . escapeshellarg($name) . ' 2>&1',
                $out,
                $code
            );
            if ($code !== 0) {
                $failures[] = [
                    'name' => $name,
                    'device' => $target['device'],
                    'exitCode' => $code,
                    'error' => mb_substr(trim(implode(' ', $out)), 0, 200),
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

        // emcmd 提交可能因起转等待超时返回非零,但 emhttp 随后仍完成唤醒;
        // 最终只把等待结束仍未起转的盘计为失败,不以提交退出码覆盖真实盘态。
        $failures = array_values(array_filter(
            $failures,
            static fn(array $failure): bool => in_array($failure['name'], $pending, true)
        ));

        $detail = mb_substr(implode(',', array_column($targets, 'name')), 0, 64);
        audit('diskwake', $detail, ($failures === [] && !$timedOut) ? 'ok' : 'fail');

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
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $root . '/' . $e;
            if (is_link($p)) {
                $symlinkFinds .= '; timeout ' . $timeout . ' find -H ' . escapeshellarg($p)
                    . ' -maxdepth 8 -iname ' . escapeshellarg('*' . $q . '*')
                    . ' -printf ' . escapeshellarg("%p\t%y\n") . ' 2>/dev/null';
            }
        }
        $cmd = PATH_ENV . ' sh -c ' . escapeshellarg(
            '{ timeout ' . $timeout . ' find ' . escapeshellarg($root)
            . ' -maxdepth 8 -iname ' . escapeshellarg('*' . $q . '*')
            . ' -printf ' . escapeshellarg("%p\t%y\n") . ' 2>/dev/null'
            . $symlinkFinds . '; } | sed ' . escapeshellarg('s|^' . $root . '/\?||')
        ) . ' | head -n ' . (SEARCH_MAX_RESULTS + 1);
    } else {
        $cmd = PATH_ENV . ' timeout ' . $timeout
            . ' find ' . escapeshellarg($root)
            . ' -maxdepth 8 -iname ' . escapeshellarg('*' . $q . '*')
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
        if ($path === '' || $path === '.') {
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
        'building' => is_file(dirname(INDEX_FILE) . '/.index-building'),
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');

    // 【续 51】CPU 温度(sysfs,不唤盘),放在栈相关 action 之前(不需要 PROJECTS_DIR)
    if ($action === 'cputemp') {
        ok(readCpuTemp());
    }

    if ($action === 'networkinfo') {
        ok(readNetworkInfo());
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

    // 【续 112】有更新的镜像 ref 列表(直读更新缓存,剥 library/ 前缀;纯文件读,不唤盘)
    if ($action === 'updates') {
        ok(readImageUpdates());
    }

    // 【续 118/120】文件搜索(显式触发):scope=cache 只读 /mnt/cache 不唤盘;
    // scope=all 扫 /mnt/user 唤醒休眠盘(前端二次确认),audit 留痕
    if ($action === 'search') {
        $scope = (string) ($_GET['scope'] ?? 'cache');
        if ($scope === 'all') {
            audit('search-all', mb_substr((string) ($_GET['q'] ?? ''), 0, 64), 'ok');
        }
        ok(searchFiles((string) ($_GET['q'] ?? ''), $scope));
    }

    // 【续 124】索引状态(设置页卡片;只读 flag/meta/文件大小,不唤盘)
    if ($action === 'indexstatus') {
        ok(indexStatus());
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
        $pcMap = projectContainersMap();
        ok(array_map(fn($n) => stackSummary($n, $lsMap, $pcMap), $names));
    }

    if ($action === 'get') {
        $name = validName($_GET['name'] ?? '');
        $dir = PROJECTS_DIR . '/' . $name;
        $composeFile = firstExisting($dir, composeFileCandidates());
        $overrideFile = firstExisting($dir, overrideFileCandidates());
        ok([
            'stack' => stackSummary($name, composeLsMap()),
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

    // 【续 147】docker 容器更新日志:进度/进行状态/结果一把全给(前端少一次往返)
    if ($action === 'dockerlog') {
        $name = (string) ($_GET['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $name)) {
            fail(400, '非法容器名');
        }
        $dir = DOCKER_UPDATE_DIR . '/' . $name;
        if (!is_dir($dir)) {
            fail(404, '无更新记录: ' . $name);
        }
        ok([
            'log' => tailFile($dir . '/update.log'),
            'running' => file_exists($dir . '/op-lock'),
            'result' => readJsonFile($dir . '/result.json'),
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

    // 磁盘唤醒(显式触发):只唤醒已确认休眠的阵列数据 HDD,起转走官方 emcmd
    if ($action === 'diskwake') {
        ok(runDiskWake());
    }

    // 【续 147】docker 容器更新(异步带 pull 进度;替代无进度的 GraphQL updateContainer)
    if ($action === 'dockerupdate') {
        $name = (string) ($body['name'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $name)) {
            fail(400, '非法容器名');
        }
        $noop = [];
        $inspectCode = 1;
        exec(PATH_ENV . ' ' . DOCKER . ' inspect ' . escapeshellarg($name) . ' >/dev/null 2>&1', $noop, $inspectCode);
        if ($inspectCode !== 0) {
            fail(404, '容器不存在: ' . $name);
        }
        // update_container 无模板会静默跳过(continue),提前 422 报清
        $tmpl = findUserTemplate($name);
        if ($tmpl === null) {
            fail(422, '该容器无 webGui 模板,无法用此方式更新(可走 Compose/命令行)');
        }
        runDockerUpdateAsync($name, $tmpl);
        ok(['async' => true]);
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
