<?php
/**
 * 【续 68.2 2026-07-28】pull/rebuild 成功后回写 unraid-api 的更新状态缓存
 *
 * 背景:/var/lib/docker/unraid-update-status.json 由 unraid-api 的 cron
 * (EVERY_DAY_AT_6AM)重写,GraphQL isUpdateAvailable 直接读这个文件
 * (isUpdateAvailableCached: local!==remote → true)。
 * pull 之后到次日 6AM 之间,文件里还是旧 digest → 前端「更新」徽章残留。
 *
 * 本脚本由 api.php 的后台异步 shell 在 pull/rebuild 成功后调用(php-cli):
 * 对该栈所有镜像,把缓存条目的 local/remote 都写成当前本地 RepoDigest
 * —— pull 刚保证了"本地==远端"这一事实,所以这是真实状态而非粉饰;
 * unraid-api cron 下次运行会重新计算并覆盖。
 *
 * 用法: php update-status.php <stack_dir>
 * 无参数 / 无镜像 / 状态文件不存在 → 静默 exit 0(绝不影响主流程)
 */

if (PHP_SAPI !== 'cli' || $argc < 2) {
    exit(0);
}

$dir = (string) $argv[1];
$statusFile = '/var/lib/docker/unraid-update-status.json';

if (!is_dir($dir) || !is_file($statusFile)) {
    exit(0);
}

// 栈内镜像列表(compose v2;失败 → 空数组静默退出)
$images = [];
exec(
    sprintf('cd %s && /usr/bin/docker compose config --images 2>/dev/null', escapeshellarg($dir)),
    $images
);
$images = array_values(array_filter(array_map('trim', $images)));
if (!$images) {
    exit(0);
}

$data = json_decode((string) file_get_contents($statusFile), true);
if (!is_array($data)) {
    exit(0);
}

$changed = false;
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
    if (!isset($data[$key]) || !is_array($data[$key])) {
        continue;
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
    if (($data[$key]['local'] ?? null) !== $digest || ($data[$key]['remote'] ?? null) !== $digest) {
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
exit(0);
