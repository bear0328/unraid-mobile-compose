<?php

declare(strict_types=1);

const WORK_DIR = '/tmp/unraid-mobile-plugins';
const INSTALLED_DIR = '/var/log/plugins';
const DOWNLOAD_DIR = '/tmp/plugins';
const PLUGIN_CLI = '/usr/local/sbin/plugin';
const CANONICAL_DIR = '/boot/config/plugins/unraid-mobile';
const EXEC_DIR = '/usr/local/emhttp/plugins/compose.manager';
const AUDIT_LOG = CANONICAL_DIR . '/audit.log';
const PATH_ENV = 'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
const LOG_LIMIT = 262144;
const LOG_KEEP = 131072;

final class PluginConflictException extends RuntimeException
{
}

function atomicJson(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        throw new RuntimeException('任务状态 JSON 编码失败');
    }
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        throw new RuntimeException('任务状态写入失败');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('任务状态原子替换失败');
    }
}

function readJson(string $path): array
{
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function appendLog(string $id, string $message): void
{
    $path = WORK_DIR . '/' . $id . '.log';
    $line = '[' . date('H:i:s') . '] ' . $message . "\n";
    if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        return;
    }
    $size = @filesize($path);
    if ($size === false || $size <= LOG_LIMIT) {
        return;
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return;
    }
    fseek($fh, -LOG_KEEP, SEEK_END);
    $tail = (string) stream_get_contents($fh);
    fclose($fh);
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $tail, LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    }
}

function validPluginId(mixed $id): string
{
    $id = (string) $id;
    if (
        !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.plg$/D', $id)
        || basename($id) !== $id
        || str_contains($id, '..')
    ) {
        throw new RuntimeException('非法插件名');
    }
    return $id;
}

function installed(string $id): bool
{
    return is_file(INSTALLED_DIR . '/' . $id);
}

function operatingSystemPlugin(string $id): bool
{
    return in_array(strtolower($id), ['unraidserver.plg', 'unraidserver-.plg'], true);
}

function managed(string $id): bool
{
    return strtolower($id) !== 'compose.manager.plg' && !operatingSystemPlugin($id);
}

function installedPluginIds(): array
{
    $ids = [];
    foreach (glob(INSTALLED_DIR . '/*.plg') ?: [] as $path) {
        $id = validPluginId(basename($path));
        if (!operatingSystemPlugin($id)) {
            $ids[] = $id;
        }
    }
    sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
    return $ids;
}

function pluginXml(string $path): SimpleXMLElement
{
    $xml = @file_get_contents($path);
    if ($xml === false || trim($xml) === '') {
        throw new RuntimeException('无法读取插件描述文件: ' . basename($path));
    }
    $dom = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    if (!$dom instanceof SimpleXMLElement) {
        throw new RuntimeException('插件 XML 解析失败: ' . basename($path));
    }
    return $dom;
}

function pluginVersion(SimpleXMLElement $dom): string
{
    $version = trim((string) ($dom->attributes()['version'] ?? ''));
    if ($version === '') {
        throw new RuntimeException('插件描述文件缺少版本');
    }
    return $version;
}

function canCheck(SimpleXMLElement $dom): bool
{
    return trim((string) ($dom->attributes()['pluginURL'] ?? '')) !== '';
}

function readCache(): array
{
    $cache = readJson(WORK_DIR . '/cache.json');
    return [
        'checkedAt' => is_string($cache['checkedAt'] ?? null) ? $cache['checkedAt'] : null,
        'plugins' => is_array($cache['plugins'] ?? null) ? $cache['plugins'] : [],
    ];
}

function saveCache(array &$cache): void
{
    if (in_array($GLOBALS['operation'] ?? null, ['plugincheck', 'pluginupdate', 'pluginupdateall'], true)) {
        $cache['checkedAt'] = date('c');
    }
    atomicJson(WORK_DIR . '/cache.json', $cache);
}

function compatibility(SimpleXMLElement $dom): ?string
{
    $versionInfo = @parse_ini_file('/etc/unraid-version');
    $unraid = is_array($versionInfo) ? trim((string) ($versionInfo['version'] ?? '')) : '';
    if ($unraid === '') {
        throw new RuntimeException('无法读取 /etc/unraid-version');
    }
    $attrs = $dom->attributes();
    $min = trim((string) ($attrs['min'] ?? ''));
    $max = trim((string) ($attrs['max'] ?? ''));
    if ($min !== '' && version_compare($unraid, $min, '<')) {
        return '需要 Unraid >= ' . $min . ',当前为 ' . $unraid;
    }
    if ($max !== '' && version_compare($unraid, $max, '>')) {
        return '仅支持 Unraid <= ' . $max . ',当前为 ' . $unraid;
    }
    return null;
}

function officialPluginProcesses(): array
{
    $lines = [];
    $code = 1;
    exec(PATH_ENV . ' ps -eo pid=,args= 2>/dev/null', $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException('无法检测官方 plugin 进程');
    }
    $running = [];
    foreach ($lines as $line) {
        if (preg_match('#(?:^|\s)(?:/usr/local/sbin/)?plugin(?:\s|$)#', $line)) {
            $running[] = trim($line);
        }
    }
    return $running;
}

function assertNoOfficialPluginProcess(): void
{
    $running = officialPluginProcesses();
    if ($running !== []) {
        throw new PluginConflictException('检测到其他官方 plugin 进程,请稍后重试');
    }
}

function runOfficial(array $args, ?string $redact = null, ?string $safeUrl = null): string
{
    assertNoOfficialPluginProcess();
    $escaped = array_map(static fn(string $arg): string => escapeshellarg($arg), $args);
    $command = PATH_ENV . ' ' . PLUGIN_CLI . ' ' . implode(' ', $escaped) . ' 2>&1';
    $lines = [];
    $code = 1;
    exec($command, $lines, $code);
    $output = implode("\n", $lines);
    if ($redact !== null && $safeUrl !== null) {
        $output = str_replace($redact, $safeUrl, $output);
    }
    if ($output !== '') {
        appendLog($GLOBALS['jobId'], $output);
    }
    if ($code !== 0) {
        $message = trim($output);
        throw new RuntimeException('官方 plugin ' . $args[0] . ' 失败(exit ' . $code . ')'
            . ($message !== '' ? ': ' . mb_substr($message, -500) : ''));
    }
    return $output;
}

function checkPlugin(string $id, array &$cache): string
{
    validPluginId($id);
    if (!installed($id)) {
        throw new RuntimeException('插件未安装: ' . $id);
    }
    appendLog($GLOBALS['jobId'], '== 检查 ' . $id . ' ==');
    $entry = is_array($cache['plugins'][$id] ?? null) ? $cache['plugins'][$id] : [];
    try {
        $installedXml = pluginXml(INSTALLED_DIR . '/' . $id);
        if (!canCheck($installedXml)) {
            $entry['status'] = 'unsupported';
            $entry['statusMessage'] = '插件未提供 pluginURL';
            $cache['plugins'][$id] = $entry;
            saveCache($cache);
            return '不支持检查更新';
        }
        runOfficial(['check', $id]);
        $latestXml = pluginXml(DOWNLOAD_DIR . '/' . $id);
        $current = pluginVersion($installedXml);
        $latest = pluginVersion($latestXml);
        $entry['latestVersion'] = $latest;
        $entry['statusMessage'] = compatibility($latestXml);
        if ($entry['statusMessage'] !== null) {
            $entry['status'] = 'incompatible';
        } else {
            $entry['status'] = strcmp($latest, $current) > 0 ? 'updateAvailable' : 'current';
        }
        $cache['plugins'][$id] = $entry;
        saveCache($cache);
        return $entry['status'] === 'updateAvailable' ? '发现新版本 ' . $latest : '已是最新或当前不可更新';
    } catch (Throwable $e) {
        $entry['status'] = 'error';
        $entry['statusMessage'] = $e->getMessage();
        $cache['plugins'][$id] = $entry;
        saveCache($cache);
        throw $e;
    }
}

function updatePlugin(string $id, array &$cache): string
{
    validPluginId($id);
    if (operatingSystemPlugin($id)) {
        throw new RuntimeException('Unraid OS 更新不属于插件管理');
    }
    $status = checkPlugin($id, $cache);
    $cachedStatus = (string) ($cache['plugins'][$id]['status'] ?? 'error');
    if ($cachedStatus !== 'updateAvailable') {
        if (in_array($cachedStatus, ['current'], true)) {
            return $status;
        }
        throw new RuntimeException((string) ($cache['plugins'][$id]['statusMessage'] ?? '插件不可更新'));
    }
    appendLog($GLOBALS['jobId'], '== 更新 ' . $id . ' ==');
    runOfficial(['update', $id]);
    $newVersion = pluginVersion(pluginXml(INSTALLED_DIR . '/' . $id));
    $cache['plugins'][$id] = [
        'latestVersion' => $newVersion,
        'status' => 'current',
        'statusMessage' => null,
    ];
    saveCache($cache);
    return '更新完成,当前版本 ' . $newVersion;
}

function validateInstallUrl(mixed $url): array
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
        throw new RuntimeException('插件 URL 必须是 HTTPS 且路径以 .plg 结尾,不允许用户信息、query 或 fragment');
    }
    $id = validPluginId(basename($parts['path']));
    if (installed($id)) {
        throw new RuntimeException('插件已安装,不覆盖: ' . $id);
    }
    $safeUrl = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parts['path'];
    return [$url, $safeUrl, $id];
}

function installPlugin(array &$job, array &$cache): string
{
    [$url, $safeUrl, $id] = validateInstallUrl($job['url'] ?? '');
    appendLog($GLOBALS['jobId'], '== 安装 ' . $id . ' ==');
    runOfficial(['install', $url], $url, $safeUrl);
    $version = pluginVersion(pluginXml(INSTALLED_DIR . '/' . $id));
    $cache['plugins'][$id] = ['latestVersion' => $version, 'status' => 'current', 'statusMessage' => null];
    saveCache($cache);
    return '安装完成,版本 ' . $version;
}

function removePlugin(string $id, array &$cache): string
{
    validPluginId($id);
    if (!installed($id)) {
        throw new RuntimeException('插件未安装: ' . $id);
    }
    if (!managed($id)) {
        throw new RuntimeException('该插件不允许移除');
    }
    appendLog($GLOBALS['jobId'], '== 移除 ' . $id . ' ==');
    runOfficial(['remove', $id]);
    unset($cache['plugins'][$id]);
    saveCache($cache);
    return '移除完成';
}

function restoreComposeManager(): void
{
    foreach (['api.php', 'update-status.php', 'plugin-worker.php'] as $file) {
        $source = CANONICAL_DIR . '/' . $file;
        if (!is_file($source) || !@copy($source, EXEC_DIR . '/' . $file)) {
            throw new RuntimeException('compose.manager 自更新恢复失败: ' . $file);
        }
    }
    @chmod(EXEC_DIR . '/plugin-worker.php', 0755);
}

function writeAudit(string $operation, string $target, string $result, string $ip): void
{
    $size = @filesize(AUDIT_LOG);
    if ($size !== false && $size > 1048576) {
        @rename(AUDIT_LOG, AUDIT_LOG . '.old');
    }
    $line = sprintf("[%s] action=%s stack=%s result=%s ip=%s\n", date('c'), $operation, $target, $result, $ip);
    @file_put_contents(AUDIT_LOG, $line, FILE_APPEND | LOCK_EX);
}

function releaseActiveLock(string $id): void
{
    $path = WORK_DIR . '/active.lock';
    if (trim((string) @file_get_contents($path)) === $id) {
        @unlink($path);
    }
}

if (PHP_SAPI !== 'cli' || $argc !== 2 || !preg_match('/^[a-f0-9]{32}$/D', (string) ($argv[1] ?? ''))) {
    fwrite(STDERR, "usage: php plugin-worker.php <job-id>\n");
    exit(2);
}

$jobId = (string) $argv[1];
$jobPath = WORK_DIR . '/' . $jobId . '.json';
$job = readJson($jobPath);
if (($job['id'] ?? null) !== $jobId) {
    fwrite(STDERR, "job not found\n");
    exit(2);
}

$globalLock = @fopen(WORK_DIR . '/worker.lock', 'c');
if ($globalLock === false || !@flock($globalLock, LOCK_EX | LOCK_NB)) {
    $job['state'] = 'failed';
    $job['error'] = '另一个插件 worker 正在运行';
    atomicJson($jobPath, $job);
    releaseActiveLock($jobId);
    exit(1);
}

$state = 'failed';
$error = null;
$results = is_array($job['results'] ?? null) ? array_values($job['results']) : [];
$current = 0;
$names = is_array($job['names'] ?? null) ? array_values($job['names']) : [];
$operation = (string) ($job['operation'] ?? '');
$cache = readCache();

try {
    if (!in_array($operation, ['plugincheck', 'pluginupdate', 'pluginupdateall', 'plugininstall', 'pluginremove'], true)) {
        throw new RuntimeException('未知插件任务');
    }
    assertNoOfficialPluginProcess();
    if ($operation === 'plugincheck') {
        $names = installedPluginIds();
    } elseif ($operation === 'pluginupdateall') {
        $names = [];
        foreach ($cache['plugins'] as $id => $entry) {
            if (is_array($entry) && ($entry['status'] ?? null) === 'updateAvailable' && installed(validPluginId($id))) {
                $names[] = $id;
            }
        }
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    } else {
        $names = [validPluginId($names[0] ?? '')];
    }

    $job['state'] = 'running';
    $job['current'] = 0;
    $job['total'] = count($names);
    $job['results'] = [];
    $job['error'] = null;
    atomicJson($jobPath, $job);
    appendLog($jobId, '任务开始: ' . $operation . ',共 ' . count($names) . ' 项');

    $results = [];
    foreach ($names as $id) {
        try {
            if ($operation === 'plugincheck') {
                $message = checkPlugin($id, $cache);
            } elseif ($operation === 'pluginupdate' || $operation === 'pluginupdateall') {
                $message = updatePlugin($id, $cache);
            } elseif ($operation === 'plugininstall') {
                $message = installPlugin($job, $cache);
            } else {
                $message = removePlugin($id, $cache);
            }
            $results[] = ['id' => $id, 'ok' => true, 'message' => $message];
        } catch (PluginConflictException $e) {
            throw $e;
        } catch (Throwable $e) {
            appendLog($jobId, $id . ': ' . $e->getMessage());
            $results[] = ['id' => $id, 'ok' => false, 'message' => $e->getMessage()];
        }
        $current++;
        $job['current'] = $current;
        $job['results'] = $results;
        atomicJson($jobPath, $job);
    }

    $failed = count(array_filter($results, static fn(array $result): bool => $result['ok'] !== true));
    $state = $failed === 0 ? 'succeeded' : ($failed === count($results) ? 'failed' : 'partial');
} catch (Throwable $e) {
    $error = $e->getMessage();
    appendLog($jobId, '任务失败: ' . $error);
    $state = 'failed';
} finally {
    if (
        in_array($operation, ['pluginupdate', 'pluginupdateall'], true)
        && in_array('compose.manager.plg', $names, true)
    ) {
        try {
            restoreComposeManager();
            appendLog($jobId, 'compose.manager 后端文件已从正本恢复');
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $state = 'failed';
            appendLog($jobId, $error);
        }
    }
    $job['state'] = $state;
    $job['current'] = $current;
    $job['results'] = $results;
    $job['error'] = $error;
    $job['finishedAt'] = date('c');
    try {
        atomicJson($jobPath, $job);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
    }
    $target = $operation === 'plugininstall'
        ? (string) ($job['safeUrl'] ?? '-')
        : (string) ($names[0] ?? '-');
    writeAudit($operation, $target, $state, (string) ($job['ip'] ?? '-'));
    flock($globalLock, LOCK_UN);
    fclose($globalLock);
    releaseActiveLock($jobId);
}
