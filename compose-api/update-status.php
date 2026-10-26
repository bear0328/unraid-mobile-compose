<?php
/**
 * 维护镜像更新状态:
 * - pull/rebuild 成功后,把主缓存已有项和补充缓存的 local/remote 回写为当前 RepoDigest;
 * - --scan 独立复查全部当前容器镜像,结果写 /tmp 补充缓存。
 *
 * 主缓存由 webGui dockerupdate 定时任务维护;补充缓存由 api.php 后台扫描,
 * 成功项每天复查、失败项 5 分钟后重试,避免 HTTP 轮询同步等待 Registry。
 * 单个镜像查询失败不影响其他镜像;无远端 digest 时不判定有无更新。
 *
 * 用法: php update-status.php <stack_dir>     — compose 栈(pull/rebuild 后)
 *       php update-status.php --image <ref>   — 单镜像容器更新后
 *       php update-status.php --scan          — 独立复查全部当前镜像
 * 无参数 / 无镜像 → 静默 exit 0;主缓存缺失或损坏时仍同步补充缓存(绝不影响主流程)
 */

if (PHP_SAPI !== 'cli' || $argc < 2) {
    exit(0);
}

$statusFile = '/var/lib/docker/unraid-update-status.json';
$extraStatusFile = '/tmp/unraid-mobile-update-status.json';
$scanLockFile = '/tmp/unraid-mobile-update-scan.lock';
const SCAN_SUCCESS_TTL = 86400;
const SCAN_RETRY_TTL = 300;

function syncExtraImageUpdates(string $extraStatusFile, string $lockFile, array $digests): void
{
    if ($digests === []) {
        return;
    }
    $lock = @fopen($lockFile, 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            @fclose($lock);
        }
        return;
    }

    try {
        $extra = json_decode((string) @file_get_contents($extraStatusFile), true);
        $extra = is_array($extra) ? $extra : [];
        foreach ($digests as $image => $digest) {
            $extra[$image] = [
                'local' => $digest,
                'remote' => $digest,
                'status' => 'undef',
                'checkedAt' => time(),
            ];
        }
        ksort($extra);
        $tmp = $extraStatusFile . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, json_encode($extra, JSON_PRETTY_PRINT)) !== false) {
            @rename($tmp, $extraStatusFile);
        } else {
            @unlink($tmp);
        }
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function scanImageUpdates(string $extraStatusFile, string $lockFile): void
{
    $lock = @fopen($lockFile, 'c');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            @fclose($lock);
        }
        return;
    }

    try {
        $previous = json_decode((string) @file_get_contents($extraStatusFile), true);
        $previous = is_array($previous) ? $previous : [];
        $lines = [];
        @exec('/usr/bin/docker ps -a --format ' . escapeshellarg('{{.Image}}') . ' 2>/dev/null', $lines);
        $targets = [];
        foreach ($lines as $line) {
            $image = trim($line);
            if ($image === '' || str_starts_with($image, 'sha256:')) {
                continue;
            }
            // 纯十六进制镜像 ID 没有可查询的仓库/tag。
            if (preg_match('/^[a-f0-9]{12,64}$/i', $image)) {
                continue;
            }
            $key = str_contains($image, ':') ? $image : $image . ':latest';
            if (!str_contains($key, '/')) {
                $key = 'library/' . $key;
            }
            $targets[$key] = true;
        }

        $extra = [];
        foreach (array_keys($targets) as $image) {
            $old = is_array($previous[$image] ?? null) ? $previous[$image] : null;
            $oldRemote = $old !== null && is_string($old['remote'] ?? null) && $old['remote'] !== ''
                ? $old['remote']
                : null;
            $needsRetry = $oldRemote === null || ($old['status'] ?? null) === 'stale';
            $ttl = $needsRetry ? SCAN_RETRY_TTL : SCAN_SUCCESS_TTL;
            $checkedAt = $old !== null && is_int($old['checkedAt'] ?? null) ? $old['checkedAt'] : 0;
            if ($old !== null && $checkedAt > 0 && time() - $checkedAt < $ttl) {
                $extra[$image] = $old;
                continue;
            }

            $out = [];
            $exitCode = 0;
            @exec(
                'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
                . ' timeout 20 /usr/bin/docker buildx imagetools inspect --raw '
                . escapeshellarg($image) . ' 2>/dev/null',
                $out,
                $exitCode
            );
            $raw = implode("\n", $out);
            $manifest = json_decode($raw, true);
            $succeeded = $exitCode === 0 && is_array($manifest) && $raw !== '';
            $remote = $succeeded ? 'sha256:' . hash('sha256', $raw) : $oldRemote;
            $extra[$image] = [
                'local' => null,
                'remote' => $remote,
                'status' => !$succeeded && $oldRemote !== null ? 'stale' : 'undef',
                'checkedAt' => time(),
            ];
        }
        ksort($extra);
        $tmp = $extraStatusFile . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, json_encode($extra, JSON_PRETTY_PRINT)) !== false) {
            @rename($tmp, $extraStatusFile);
        } else {
            @unlink($tmp);
        }
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

if ($argv[1] === '--scan') {
    scanImageUpdates($extraStatusFile, $scanLockFile);
    exit(0);
}

$images = [];
if ($argv[1] === '--image') {
    // 【续 147】单镜像模式:ref 为空静默退出
    $one = trim((string) ($argv[2] ?? ''));
    if ($one !== '') {
        $images = [$one];
    }
} else {
    $dir = (string) $argv[1];
    if (!is_dir($dir)) {
        exit(0);
    }
    // 栈内镜像列表(compose v2;失败 → 空数组静默退出)
    exec(
        sprintf('cd %s && /usr/bin/docker compose config --images 2>/dev/null', escapeshellarg($dir)),
        $images
    );
    $images = array_values(array_filter(array_map('trim', $images)));
}
if (!$images) {
    exit(0);
}

$data = json_decode((string) @file_get_contents($statusFile), true);
$data = is_array($data) ? $data : [];

$changed = false;
$currentDigests = [];
foreach ($images as $img) {
    // 缓存文件 key 格式是 repo:tag(无 tag 补 :latest)
    $key = str_contains($img, ':') ? $img : $img . ':latest';
    // 【续 112.1 2026-08-22】key 还要做 PHP ensureImageTag 同款归一化:无 '/' 的
    // Docker Hub 官方镜像补 library/ 前缀 —— 缓存由 reloadUpdateStatus 以
    // library/postgres:18 形式写入,之前拿 postgres:18 直接匹配永远落空,
    // pull 后陈旧 local!=remote 条目残留 → 徽章不消
    if (!str_contains($key, '/')) {
        $key = 'library/' . $key;
    }
    // 当前本地 RepoDigest(形如 repo@sha256:...;本地构建/无 digest 的镜像跳过)
    $out = [];
    exec(
        '/usr/bin/docker image inspect ' . escapeshellarg($img)
        . " --format '{{index .RepoDigests 0}}' 2>/dev/null",
        $out
    );
    $repoDigest = trim((string) ($out[0] ?? ''));
    $at = strpos($repoDigest, '@');
    if ($at === false) {
        continue;
    }
    $digest = substr($repoDigest, $at + 1); // 'sha256:...'
    if ($digest === '') {
        continue;
    }
    $currentDigests[$key] = $digest;
    if (isset($data[$key]) && is_array($data[$key])
        && (($data[$key]['local'] ?? null) !== $digest || ($data[$key]['remote'] ?? null) !== $digest)) {
        $data[$key]['local'] = $digest;
        $data[$key]['remote'] = $digest;
        $changed = true;
    }
}

if ($changed) {
    // 原子写:临时文件 + rename,避免和 unraid-api cron 写冲突时留下半截 JSON
    $tmp = $statusFile . '.tmp-' . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
    @rename($tmp, $statusFile);
}
syncExtraImageUpdates($extraStatusFile, $scanLockFile, $currentDigests);
exit(0);
