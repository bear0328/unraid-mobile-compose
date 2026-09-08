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
 *   GET  ?action=vminfo&vm=X                VM 详情增强(续 101:virsh dumpxml/dominfo/
 *                                           snapshot-list + /etc/libvirt/qemu 回退,全只读)
 *   GET  ?action=updates                    有更新的镜像 ref 列表(续 112:直读 unraid-api
 *                                           更新缓存并剥 library/ 前缀 —— GraphQL
 *                                           isUpdateAvailable 用容器 image 原值查缓存,
 *                                           官方镜像 key 是 library/postgres:18 永远查不到,
 *                                           前端拿本列表兜底)
 *   GET  ?action=list                       栈列表(名称/状态/autostart/last_result)
 *   GET  ?action=get&name=X                 栈详情(compose.yaml/override/log)
 *   GET  ?action=log&name=X                 操作日志 + 是否有异步任务在跑
 *   PUT  {action: up|down|restart, name}    同步执行(快操作) — Content-Type: application/json
 *   PUT  {action: pull|rebuild, name}       异步执行(慢操作,前端轮询 log)
 *   PUT  {action: autostart, name, value}   value = "true"|"false"
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
// 【续 112】unraid-api 的容器更新状态缓存(webGui dockerupdate cron / DockerUpdate.php 写)
const UPDATE_STATUS_FILE = '/var/lib/docker/unraid-update-status.json';

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
 * 【续 101 2026-08-10】VM 详情增强:读 libvirt XML 返回 vcpus/内存/磁盘/网卡/
 * 图形/直通/快照(GraphQL VmDomain 只有 id/name/state,做不了详情)。
 * 只读操作:virsh dumpxml/dominfo/snapshot-list + qemu-img info,无任何写。
 * 安全:vm 名走 validName 同款白名单正则(不含项目目录存在性检查——VM 与
 * compose 项目无关);所有 shell 参数 escapeshellarg。
 */
function readVmInfo(string $vm): array
{
    $arg = escapeshellarg($vm);
    // 优先 virsh dumpxml;不可用/返回空 → 回退读 /etc/libvirt/qemu/<vm>.xml
    $xml = (string) @shell_exec(PATH_ENV . ' virsh dumpxml ' . $arg . ' 2>/dev/null');
    if (trim($xml) === '') {
        $xml = (string) @file_get_contents('/etc/libvirt/qemu/' . $vm . '.xml');
    }
    if (trim($xml) === '') {
        fail(404, '无法读取 VM XML(virsh 不可用且无配置文件): ' . $vm);
    }
    $dom = @simplexml_load_string($xml);
    if ($dom === false) {
        fail(500, 'VM XML 解析失败: ' . $vm);
    }

    $attr = static fn($node, string $name): ?string =>
        isset($node[$name]) ? (string) $node[$name] : null;

    // vcpus:<vcpu> 内容;属性 current 存在时优先(未指定上限场景)
    $vcpus = null;
    if (isset($dom->vcpu)) {
        $current = $attr($dom->vcpu, 'current');
        $vcpus = (int) ($current !== null ? $current : (string) $dom->vcpu);
    }

    // memory:<memory>=上限 <currentMemory>=当前,unit 默认 KiB
    $memory = null;
    if (isset($dom->memory)) {
        $memory = [
            'max' => (int) (string) $dom->memory,
            'current' => isset($dom->currentMemory) ? (int) (string) $dom->currentMemory : (int) (string) $dom->memory,
            'unit' => $attr($dom->memory, 'unit') ?? 'KiB',
        ];
    }

    // autostart:virsh dominfo 的 "Autostart:" 行
    $autostart = null;
    $dominfo = (string) @shell_exec(PATH_ENV . ' virsh dominfo ' . $arg . ' 2>/dev/null');
    if (preg_match('/^Autostart:\s*(\w+)/m', $dominfo, $m)) {
        $autostart = strtolower($m[1]) === 'enable';
    }

    // disks:<disk device='disk'>;大小走 qemu-img info(timeout 5s),失败只返回 path
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
        ];
        if ($path !== null && $type === 'file') {
            $imgOut = (string) @shell_exec(
                PATH_ENV . ' timeout 5 qemu-img info --output json ' . escapeshellarg($path) . ' 2>/dev/null'
            );
            $img = json_decode($imgOut, true);
            if (is_array($img) && isset($img['virtual-size'])) {
                $entry['size'] = (int) $img['virtual-size'];
            }
        }
        $disks[] = $entry;
    }

    // interfaces:<interface type='bridge|network'>
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

    // graphics:<graphics type='vnc|spice'>
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

    // hostDevices:<hostdev type='pci|usb'>
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

    // snapshots:virsh snapshot-list --name,按行拆分
    $snapshots = [];
    $snapOut = (string) @shell_exec(PATH_ENV . ' virsh snapshot-list ' . $arg . ' --name 2>/dev/null');
    foreach (preg_split('/\r?\n/', trim($snapOut)) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $snapshots[] = $line;
        }
    }

    return [
        'name' => isset($dom->name) ? (string) $dom->name : $vm,
        'uuid' => isset($dom->uuid) ? (string) $dom->uuid : null,
        'vcpus' => $vcpus,
        'memory' => $memory,
        'autostart' => $autostart,
        'disks' => $disks,
        'interfaces' => $interfaces,
        'graphics' => $graphics,
        'hostDevices' => $hostDevices,
        'snapshots' => $snapshots,
    ];
}

/**
 * 【续 112 2026-08-22】有更新的镜像 ref 列表:直读 unraid-api 更新状态缓存。
 * 背景:GraphQL isUpdateAvailable 用容器 image 原值(如 postgres:18)查缓存,
 * 而缓存 key 由 PHP ensureImageTag 归一化(library/postgres:18)→ 官方镜像永远
 * 查不到(null),徽章不显示。本端点剥掉 library/ 前缀返回,前端按 image 直接比对。
 *
 * 【续 112.2】local digest 不再信缓存:PHP reloadUpdateStatus 只在缓存没有 local
 * 时才实测,已有值永久复用 —— 绕开 webGui/compose-api 的镜像更新(watchtower、
 * 手动 docker pull)会让缓存 local 永远冻结,徽章假阴性(msgo 实证:缓存
 * local==remote==旧值,实际 tag 已指向新 digest)。改为每次实测 tag 当前 digest
 * 与缓存 remote 比;缓存 remote 的新鲜度由 webGui dockerupdate cron 保证。
 * 【续 112.3】实测走逐 ref `docker image inspect`(RepoDigests[0],与 webGui
 * inspectLocalVersion 同语义);不用 docker images 行输出(多 RepoDigest 干扰)。
 * 降级:tag 本地不存在(如已删)或 remote 缺失 → 回退缓存原值比对/status 判定。
 */
function readImageUpdates(): array
{
    $data = readJsonFile(UPDATE_STATUS_FILE);
    if ($data === null) {
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

// ---------- 路由 ----------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? '');

    // 【续 51】CPU 温度(sysfs,不唤盘),放在栈相关 action 之前(不需要 PROJECTS_DIR)
    if ($action === 'cputemp') {
        ok(readCpuTemp());
    }

    // 【续 101】VM 详情(libvirt XML 只读;vm 名白名单正则,不查项目目录)
    if ($action === 'vminfo') {
        $vm = (string) ($_GET['vm'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $vm)) {
            fail(400, '非法 VM 名');
        }
        ok(readVmInfo($vm));
    }

    // 【续 112】有更新的镜像 ref 列表(直读更新缓存,剥 library/ 前缀;纯文件读,不唤盘)
    if ($action === 'updates') {
        ok(readImageUpdates());
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
