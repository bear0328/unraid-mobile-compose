# unRAID Mobile

**[English](README.md)** | 简体中文

[![Telegram 交流群](https://img.shields.io/badge/Telegram-交流群-2CA5E0?logo=telegram&logoColor=white)](https://t.me/+l1iA02ZkOK1lNmEx)
[![TestFlight 公开测试](https://img.shields.io/badge/TestFlight-公开测试-0D96F6?logo=apple&logoColor=white)](https://testflight.apple.com/join/c9apZvbG)
[![Docker 镜像版本](https://img.shields.io/docker/v/bear0328/unraid-mobile?sort=semver&label=docker)](https://hub.docker.com/r/bear0328/unraid-mobile)
[![Docker Pulls](https://img.shields.io/docker/pulls/bear0328/unraid-mobile)](https://hub.docker.com/r/bear0328/unraid-mobile)

专为移动设备优化的 unRAID 服务器管理界面。React 18 + TypeScript + Vite + Tailwind CSS,
单容器部署,数据走 unRAID GraphQL API(7.2+;磁盘休眠图标和网络速率统计需要
unRAID 7.3+ / unraid-api ≥ 4.20,老版本会自动降级,仅缺少这两项,其余功能正常)。

## 界面截图

| 仪表盘 | 容器 / VM | Compose 栈 |
|---|---|---|
| ![仪表盘](docs/screenshots/01-dashboard.png) | ![容器/VM](docs/screenshots/02-containers.png) | ![Compose 栈](docs/screenshots/03-compose.png) |

| 共享文件 | 日志 | 设置 / License |
|---|---|---|
| ![共享文件](docs/screenshots/04-shares.png) | ![日志](docs/screenshots/05-logs.png) | ![设置/License](docs/screenshots/06-settings-license.png) |

## 功能(免费版 / Pro)

| 免费版(开箱即用) | Pro(License key 解锁) |
|------|------|
| 仪表盘全部监控:CPU / 内存 / 网络 / 磁盘 / 阵列 / parity 校验进度 / 历史曲线 / 收藏、unRAID 告警徽章 | 容器 / VM 启停 / 重启 / 暂停 / 恢复操作、**VM 增强详情**¹(CPU/内存/磁盘/网络/直通/快照,读 libvirt XML) |
| 容器 / VM 列表、容器详情(端口/挂载/网络/磁盘占用)、容器日志、VM 基础详情 | **Compose 栈管理**(列表/日志/up/down/pull/rebuild/yaml 编辑)¹、**插件管理**(检查/安装/更新/卸载,原生端)¹ |
| Shares 文件浏览 / 下载 / 图片预览、磁盘/缓存池只读浏览、宿主系统日志(syslog) | **CPU 温度**¹(直读 /sys/class/hwmon,不唤盘)、Shares 写操作(上传/新建/删除/重命名/文本编辑) |
| 全局搜索、命令面板、配置备份/导入、单服务器、深色主题、PWA | **容器一键更新**(单个/批量,webGui 同款)、**UPS 监控**(电量/续航/负载)、容器批量操作、告警通知(Webhook)、磁盘清理 |

¹ 依赖宿主后端(compose-api)——需按下方「Pro 宿主后端」一节在 unRAID 宿主安装小组件,
**安装会修改开机脚本 `/boot/config/go`,请先知悉其中的风险说明**。免费版无需安装任何东西。

**宿主后端依赖原则**:Compose 管理 / CPU 温度均依赖宿主 `api.php`
(安装到 `/boot/config/plugins/unraid-mobile/`),此类功能均为 Pro。

Pro 为**买断制**:一次付费永久使用(含 1 年更新)。在「设置 → License」输入 key 即解锁。
2026-11-18 起旧格式 key 全部停用，需重新签发。新 key 在首次激活时需联网自动绑机，仅上传 key 与
flashGuid 的 SHA-256 哈希，不上传完整 key、原始 GUID 或邮箱。购买渠道见 GitHub Releases / 后续公告。

## 告警通知(Webhook)

「设置 → Webhook 通知」可把服务器事件推送到 **Bark / Telegram / Discord / 飞书(自定义机器人,
支持签名校验)/ Email(Resend)/ 自定义 HTTP**。事件源按需开启(默认仅容器停止):

- 状态变化:容器停止、VM 停止、UPS 市电变化/电池低电、奇偶校验开始/结束(有同步错误升级
  error)、磁盘状态异常、阵列启停
- 阈值告警(带迟滞 + 冷却):CPU 占用、内存占用、CPU 温度(Pro)、磁盘温度、UPS 低电量
- 其他:**unRAID 原生告警转发**(系统通知中心 ALERT/WARNING,含 SMART 等)、日志关键字命中

磁盘类事件沿用 30 分钟磁盘缓存节拍,不会因监控额外唤醒休眠盘。消息模板变量:
`{{container}} {{state}} {{server}} {{time}} {{category}}`。

## 快速开始（Compose）

> 只使用一份 Compose、一个应用镜像和一个常驻容器。容器不持有宿主特权，也不会自动安装
> Unraid 插件或修改宿主文件；Pro 宿主后端需按下文单独引导。

```bash
mkdir -p /mnt/user/appdata/unraid-mobile/compose/secrets
cd /mnt/user/appdata/unraid-mobile/compose
curl -fsSL -O https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/master/docker-compose.yml
curl -fsSL -o .env https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/master/.env.example
# 编辑 .env:填写 UNRAID_UPSTREAM / UNRAID_SERVER_URL
```

用不回显、不写 shell history 的方式创建两个 secret 文件：

```bash
read -r -s -p 'WebDAV password: ' SECRET; echo; printf '%s' "$SECRET" > secrets/dav_password
read -r -s -p 'Syslog password: ' SECRET; echo; printf '%s' "$SECRET" > secrets/log_password
unset SECRET
chmod 600 .env secrets/*
docker compose up -d
docker compose logs unraid-mobile
```

打开 `http://<unraid-IP>:3999`，在设置页填写 unRAID API key 和相同的 WebDAV/日志密码。
API key 只保存在当前浏览器或原生设备中，不作为 Compose secret 下发；secret 文件由用户持有且权限为 600。
主容器仅在启动时生成或校验 bcrypt 认证文件，已有 `settings.json` 不会被覆盖。免费版到此即可使用；
需要 Compose 管理等 Pro 宿主功能时，再执行下方 `bootstrap-host.sh`。

## 仅基础功能：docker run

```bash
docker run -d \
  --name unraid-mobile \
  -p 3999:80 \
  -e UNRAID_UPSTREAM=http://192.168.1.100:8001 \
  -v /mnt/user/appdata/unraid-mobile/config:/usr/share/nginx/html/config \
  bear0328/unraid-mobile:latest
```

`UNRAID_UPSTREAM` 填你的 unRAID webGui 地址(不带尾部斜杠)——容器内 nginx 把 `/graphql`
反代到这里。**填错则全部 API 请求 502。**

打开 `http://<unraid-IP>:3999`,进「设置」页填:

1. **服务器地址** — 如 `http://192.168.1.100`(填 unRAID webGui 地址,不带尾部斜杠)
2. **API 密钥** — unRAID GraphQL API key(webGui → Settings → API Keys 生成)

> apiKey 只存在你自己浏览器的 localStorage,**不会**写到服务器任何文件。

镜像 tag:`latest`(最新稳定) / `1.3.8`(固定版本)。仅 linux/amd64(unRAID 平台)。

### 可选：unRAID Docker UI 模板

不习惯命令行的话,可以用模板 [`templates/unraid-mobile.xml`](templates/unraid-mobile.xml)
在 Docker 标签页里填表部署:

```bash
# 在 unRAID 宿主上以 root 执行
curl -fsSL -o /boot/config/plugins/dockerMan/templates-user/my-unraid-mobile.xml \
  https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/master/templates/unraid-mobile.xml
```

然后:Docker 标签页 → **Add Container** → 模板下拉选 **unraid-mobile** →
填 `UNRAID_UPSTREAM` → Apply。文件管理/日志/Compose 等可选挂载在高级区,
不需要的行删掉即可。

### 功能 ↔ 挂载对照表

基础功能(仪表盘/容器/VM/设置)**零挂载可用**。进阶功能按需加挂载:

| 功能 | 挂载 / 依赖 | 说明 |
|------|------------|------|
| 配置持久化 | `-v .../config:/usr/share/nginx/html/config` | 只存 serverUrl,建议加 |
| 文件管理 | `-v /mnt/user:/mnt/user` + `-v /mnt/cache:/mnt/cache` + WebDAV 密码文件 | 密码在设置页输入,与 nginx `.davpasswd` 一致 |
| 磁盘/缓存池只读浏览(共享页 → 硬盘页签) | `-v /mnt:/mnt:ro` | 只读;新加盘/池需 recreate 容器才可见 |
| 宿主系统日志 | `-v /var/log:/hostlog:ro` + 日志密码文件 | 同上,`.logpasswd` |
| Compose 栈管理 / CPU 温度(Pro) | `-v /var/run/php-fpm.sock:/hostrun/php-fpm.sock` + 宿主后端 | 见下节 |

完整 Compose 以 [`docker-compose.yml`](docker-compose.yml) 为唯一来源，默认启用上述挂载；不再在文档复制第二份示例。
当前版本只支持单服务器，始终只运行 `unraid-mobile` 一个常驻应用容器；License 服务是独立项目，不在此 Compose 内。

## Pro 宿主后端(compose-api):Compose / 插件管理 / CPU 温度

Compose、`docker run` 和 Docker UI 模板都只负责应用容器，不会自动安装本节后端。需要这些 Pro 功能时，
在 Unraid 宿主上显式执行下面的 `bootstrap-host.sh`；它会先校验 GraphQL 与 API key，再调用既有幂等安装器。

Compose、插件管理和 CPU 温度(均为 Pro 功能)依赖一个宿主端小组件(`api.php`,经宿主 php-fpm
以 root 执行 `docker compose` / 官方 `plugin` CLI / 直读 `/sys/class/hwmon`)。
未安装时对应功能显示安装指引或占位,**其余功能不受影响**。

Web 与原生端支持 Community Applications 搜索、分类、详情、HTTPS `.plg` 插件安装及官方 Docker 模板安装。
所有插件任务在宿主后台串行执行并保留日志。插件作者提供的脚本会以 root 运行;只安装可信来源,
卸载及配置清理由插件自身定义。Unraid OS 更新不在此功能范围内。

> ⚠️ **风险说明(安装前必读)**
> 安装脚本会修改以下持久宿主文件(unraid-mobile 自身改动带【unraid-mobile】标记):
> - `Compose Manager Plus`:未安装时从作者官方仓库的固定提交安装;插件自身文件和配置由该插件管理
> - `/boot/config/go`:开机脚本,追加恢复钩子(6 行),用于重启后恢复 Pro 后端组件、清理 Compose 残留锁
> - `/boot/config/plugins/unraid-mobile/api.php`:Pro 后端(Compose / CPU 温度 / VM 详情)flash 正本
> - `/boot/config/plugins/unraid-mobile/plugin-worker.php`:插件后台任务 worker flash 正本
> - `/boot/config/plugins/unraid-mobile/update-status.php`:Compose 更新徽章回写脚本 flash 正本
> - `/boot/config/plugins/unraid-mobile/apikey`:API key 的 sha256 哈希(明文不落 flash;FAT 权限由挂载选项控制)
> - `/boot/config/plugins/unraid-mobile/audit.log`:api.php 关键操作审计日志
> - `/boot/config/go.unraid-mobile-bak`:安装前自动备份的 `go` 文件
>
> 保障措施:
> - 修改前自动备份 `/boot/config/go` 为 `/boot/config/go.unraid-mobile-bak`
> - 仅追加带【unraid-mobile】标记的恢复钩子,不改动你已有的任何行
> - 脚本执行时会要求输入 `YES` 显式确认
> - 卸载后端:删除 `/boot/config/plugins/unraid-mobile/` 和 `go` 里标记的恢复钩子;如不再需要本次自动安装的 Compose Manager Plus,需在 Unraid 插件管理中单独卸载
>
> 如果你不接受对开机脚本的任何修改,不要安装 —— 免费版的全部功能都不需要它。

免费版无需任何第三方 Unraid 插件。Pro 宿主后端只额外依赖 **Compose Manager Plus**
(内部插件 ID 仍为 `compose.manager`,与旧版共用目录);
**不需要先安装 Community Applications 或 User Scripts**。下面的命令会在 Unraid 上创建私有临时目录，
下载宿主 bootstrap 和既有安装器；其余后端文件由安装器自动下载，其中 `api.php` 和 `plugin-worker.php` 会做 SHA-256 校验：

```bash
# 在 Unraid 机器的 SSH/终端中以 root 执行
um_install_dir="$(mktemp -d /tmp/unraid-mobile-install.XXXXXX)" \
  && mkdir -p "$um_install_dir/scripts" "$um_install_dir/compose-api" \
  && curl -fsSL -o "$um_install_dir/scripts/bootstrap-host.sh" \
    https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/v1.5.1/scripts/bootstrap-host.sh \
  && curl -fsSL -o "$um_install_dir/compose-api/install-compose-api.sh" \
    https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/v1.5.1/compose-api/install-compose-api.sh \
  && bash "$um_install_dir/scripts/bootstrap-host.sh"
```

bootstrap 默认通过 `http://127.0.0.1/graphql` 校验 API key；如本机 GraphQL 使用其他地址，追加
`--upstream http://127.0.0.1:8001`。校验通过后才会进入安装器：风险确认(输 YES)→ 检测 compose.manager
(**Plus 或兼容旧版已安装均跳过**；缺失时通过 Unraid 官方 `plugin` 命令从作者仓库安装 Compose Manager Plus)
→ 将 apiKey 以 `sha256:` 哈希写入 `/boot/config/plugins/unraid-mobile/apikey`(明文不落 flash 盘)
→ 装 api.php 到 Compose Manager Plus 插件目录 → 备份并加 `/boot/config/go` 恢复钩子。
可重复执行，不会重复安装已有插件。`--local-only` 用于离线恢复，此时缺少 Compose Manager Plus 会中止并提示手动安装。

Compose 已默认挂载 php-fpm.sock；使用 `docker run` 或自定义模板时需自行添加该挂载并重建容器，
Compose tab 与 CPU 温度(Pro 激活后)即可用。

## 从源码构建

```bash
git clone https://github.com/bear0328/unraid-mobile.git
cd unraid-mobile
npm ci
npm run build        # 产物在 dist/
npm test             # vitest
docker build -t unraid-mobile .
```

## unRAID GraphQL API 限制说明

### Docker 容器
| 功能 | 支持 | 字段 |
|------|------|------|
| 列表 | ✅ | id, names, image, state, status, autoStart, created |
| 日志 | ✅ | logs(tail) { lines { timestamp, message } } |
| 统计 | ✅ | stats { cpuPercent, memUsage, memPercent }(订阅) |
| 启停 | ✅ | mutation |
| 普通容器参数编辑 | ✅(Pro) | 原生 iOS；compose-api 编辑 Unraid 用户模板并通过官方 `xmlToCommand` 重建 |
| 端口映射 / 挂载 / 网络 / 磁盘占用 | ✅ | ports / mounts / networkSettings / size*(详情查询) |

### 虚拟机
| 功能 | 支持 | 字段 |
|------|------|------|
| 列表 | ✅ | domains { name, uuid, state } |
| 启停 | ✅ | mutation |
| 静态配置 | ✅(Pro) | compose-api 读取 libvirt XML：vCPU/内存分配/磁盘/网络/直通/快照/固件 |
| 实时性能 / QEMU 日志 | ✅(Pro) | compose-api 读取 libvirt domstats 原始计数器与 QEMU/libvirt 日志；仅单 VM 详情按需采样 |

## 更新日志

完整历史见 [CHANGELOG_CN.md](CHANGELOG_CN.md)。

### TestFlight 2.0.0(112)(2026-11-21)

- 容器与共享页恢复系统标准的左侧大标题，分类切换改为顶部独立下拉按钮，修复 111 中标题位置异常和标题菜单无法点击的问题；容器 / Compose 栈 / 虚拟机及共享 / 硬盘切换均已实际点击验证
- 日志页将刷新放入顶部工具栏，自动刷新 / 跳到顶 / 日志告警收进相邻选项菜单，移除常驻控制行；筛选、行数档、导出和告警横幅保持不变
- 仅原生 App 更新，Web、Docker 和 compose-api 无变化，无需重跑安装脚本

### TestFlight 2.0.0(111)(2026-11-21)

- 容器与共享页移除常驻分段栏，改用导航标题下拉菜单，为列表和搜索释放纵向空间；容器页切换容器 / Compose 栈 / 虚拟机，共享页切换共享 / 硬盘
- 标题显示明确的下拉指示，搜索、排序、刷新、批量操作及原有跳转保持不变；仅原生 App 更新，Web、Docker 和 compose-api 无变化，无需重跑安装脚本

### TestFlight 2.0.0(110)(2026-11-21)

- 播放控制栏的视频简易信息新增当前视频轨标称码率，片源未提供时省略，不使用输入或 Demux 总码率代替
- 编码名称简化显示：H.264 不再显示为 `H264 - MPEG-4 AVC (PART 10)`，AAC 不再显示为 `MPEG AAC AUDIO`
- 仅原生 App 更新，Web、Docker 和 compose-api 无变化，无需重跑安装脚本

### TestFlight 2.0.0(109)(2026-11-21)

- 播放器控制栏左下角新增简易媒体信息，展示分辨率、视频编码、帧率和当前音轨的编码、声道、语言及标称码率；缺失字段自动省略，切换音轨后同步更新
- 新增播放控制锁定：锁定后屏蔽进度、播放、设置和关闭等触控，轻点画面仅唤出解锁按钮，5 秒无操作自动隐藏
- 媒体摘要起播后缓存、轨道变化时更新，不随进度高频解析；仅原生 App 更新，Web、Docker 和 compose-api 无变化，无需重跑安装脚本

### TestFlight 2.0.0(108)(2026-11-21)

- 原生播放器新增媒体详情，展示视频编码、分辨率、帧率、标称码率、音轨、字幕轨和实时播放统计；详情面板支持半屏/全屏，窄屏自动收进播放设置菜单
- MobileVLCKit 升级至 3.7.5，包含上游安全与崩溃修复；不包含 4.0 的 Dolby Vision Profile 5 支持，MKV P5 偏色仍需等待后续播放内核支持
- 仅原生 App 更新，Web、Docker 和 compose-api 无变化，无需重跑安装脚本

### v1.5.1(2026-11-21)

- 修复用户 Docker 模板中 `file://` 本地图标被忽略的问题，Unraid Mobile 等容器及对应 Compose 栈可正常显示已有图标
- 本地图标仅限 Unraid 标准 `docker-icons` 目录，经格式与大小校验后通过 API Key 鉴权读取，不暴露宿主路径。compose-api 已更新，**升级后需重跑安装脚本**；无需新原生构建

### v1.5.0(2026-11-21)

- Web 与原生 iOS 的 Docker、Compose 列表和详情统一显示应用图标；自动匹配覆盖用户/社区模板、Community Applications 和 Dashboard Icons，Compose 优先使用同名主容器或主服务图标，避免辅助容器图标错配
- Web 详情新增手工 HTTPS 图标、本地上传 PNG/JPEG/WebP（最大 2MB）和恢复自动匹配；设置保存在 Unraid 并同步到 App，不修改 Docker 模板或 Compose 文件，也不触发容器重建。上传图片通过 API Key 鉴权读取，公网图片请求不会携带密钥
- compose-api 已更新，**升级后需重跑安装脚本**。原生版本为 TestFlight 2.0.0(107)

### v1.4.9(2026-11-20)

- 原生 iOS 新增 Pro 普通 Docker 容器参数编辑，基于 Unraid 用户模板，支持后台进度、秘密保留、运行状态保持、冲突保护和失败恢复
- 已有本地镜像不会被拉取或升级；Compose 成员、无用户模板、Tailscale 专用容器及重命名仍不支持。compose-api 已更新，**升级后需重跑安装脚本**。原生版本为 TestFlight 2.0.0(104)

### v1.4.8(2026-11-19)

- 新增 Web 安全只读文本预览，并扩展 Web 与原生 iOS 的 NFO、字幕、播放列表、图片、音频、视频及 QuickLook 文档格式支持
- Web 视频解码失败与原生 QuickLook 不支持的文档现在都有明确提示和回退处理

### v1.4.7(2026-11-19)

- 单容器升级遇到空占位 secret 时保留已有 DAV/日志 bcrypt 凭据；全新安装仍拒绝空 secret

### v1.4.6(2026-11-19)

- 部署收敛为一份 Compose、一个应用镜像和一个非特权常驻容器；Web 与原生端退役 setup/router 及多服务器功能，备份迁移仅保留 active 配置
- 新增独立幂等 Pro 宿主 bootstrap，并完善媒体预览、中文字幕与文件类型图标

### v1.4.4(2026-11-16)

- 修复 Web 告警弹层标题在已读后仍显示通知总数；标题未读数现在与铃铛角标同步减少

### v1.4.3(2026-11-16)

- Web 告警支持单条及全部已读，角标只统计未读；已读状态按服务器本地保存，数量清零后仍可回看历史告警

### v1.4.1(2026-11-15)

- 单服务器 Compose 默认不再启动或依赖 router；Web 多服务器改用 `multi-server` profile 手动启用，Nginx 在 router 未启动时也能正常运行

### v1.4.0(2026-11-15)

- 全功能 Compose 升级为 `setup + Web + router` 三服务：setup 一次性安装并校验宿主后端，Web 保持非特权，router 仅按白名单转发受控协议；Web 多服务器支持独立 DAV/日志密码，查询、缓存、连接和危险操作均按目标隔离
- compose-api 缺失或临时不可用时容器更新安全回退 GraphQL；修复切机旧响应、统计流和缓存串机，以及带端口 Host 的远端 DAV 改写。未知路由、任意上游、重复地址和缺失凭据全部 fail-closed

### v1.3.33(2026-11-15)

- CA 安装去掉 `$_POST`/`CreateDocker.php` 耦合：CA 模板 XML 直接写为用户模板 `my-<name>.xml`，先 `docker pull` 拿进度再调官方 `update_container` 创建（与容器更新同一路径）。compose-api 已更新，**升级后需重跑安装脚本**

### v1.3.32(2026-11-15)

- 调试页新增「宿主能力」卡片，由 compose-api 新增 `?action=capabilities` 提供：Unraid/PHP 版本、正本与执行位置 api.php 哈希核对、启动恢复错误和各内部依赖可用性，Unraid OS 升级后按依赖明确降级而不是运行时报错；宿主侧可用 `scripts/verify-host-compat.sh` 做同样检查
- 安装脚本新增 `--local-only`，OS 升级恢复时不再用远程固定旧版覆盖本地修复，校验值可外置到 `SHA256SUMS`；启动恢复钩子失败写入 `restore-error.log`，并通过首尾标记范围删除保证重复安装幂等
- deploy-stable.sh 改用 `readlink -f` 解析真实路径，适配 Unraid 7.4 自带的 rsync 3.5 拒绝跟随 nobody 拥有的 `/mnt/user/appdata` 符号链接。compose-api 与安装脚本已更新，**升级后需重跑安装脚本**

### v1.3.31(2026-11-15)

- 原生总览资源卡默认仅显示核心百分比或网络收发速率与轻量趋势，详细指标和控制项按需展开。原生版本为 TestFlight 2.0.0(87)
- 文件索引在异常中断或宿主重启后可识别并清理 stale 锁，通过 PID 与进程命令避免误清进行中的构建。compose-api 与索引脚本已更新，**升级后需重跑安装脚本**

### v1.3.30(2026-11-07)

- 原生插件详情新增「更新说明」，展示当前版本到最新版本之间的新增内容；compose-api 已更新，**升级后需重跑安装脚本**。原生版本为 TestFlight 2.0.0(85)
- 容器页手动刷新会清除已完成的更新结果卡；总览辅助元信息失败时自动降级为本地版本查询，减少 unraid-api 重启期间的瞬时错误

### v1.3.29(2026-11-01)

- 全端 App 图标改为橙色圆形底、深色粗 U 与阵列状态灯，移除 Unraid 原版条形标志，提高小尺寸辨识度与品牌区分度。原生版本为 TestFlight 2.0.0(82)

### v1.3.28(2026-10-31)

- 原生总览新增 CPU 实时功耗、System/Docker/VM/Cache 分类内存和 Intel/NVIDIA GPU 指标卡；网络速率以 KB 为最小单位，零值统一显示 `0 KB/s`。原生版本为 TestFlight 2.0.0(65)
- 总览卡片支持持久化拖拽排序、菜单上下移动与缩放。compose-api 新增只读 `systemmetrics`，**升级后需重跑安装脚本**

### v1.3.27(2026-10-31)

- 双端单个和批量删除统一为直接 `DELETE`，移除 5 秒撤销与回收站写入，确认时明确提示不可撤销。原生版本为 TestFlight 2.0.0(64)
- 搜索与索引全面排除 `.trash` 及其子内容，旧索引中的回收站条目不再展示。compose-api 与索引脚本已更新，**升级后需重跑安装脚本并重建文件索引**

### v1.3.26(2026-10-30)

- 修复 Web 批量删除文件夹的软删除路径，以及原生搜索关键词特殊字符被误解析的问题；文件与文件夹名称搜索和索引不再遗漏 8 层以下的路径。原生版本为 TestFlight 2.0.0(61)
- 磁盘唤醒支持并行提交与慢起转等待状态，CA 模板安装补齐数字 Target、续装与运行状态判定。compose-api 与索引脚本已更新，**升级后需重跑安装脚本并重建文件索引**

### v1.3.25(2026-10-27)

- 双端新增 CA 应用市场、官方 Docker 模板安装、Parity 校验控制和 Pro 电源操作；文件预览覆盖图片、视频和 PDF。原生新增 App 锁与可恢复的后台上传中心，原生版本为 TestFlight 2.0.0(38)
- 修复 Compose 更新徽章反复出现、唤醒后休眠状态被旧快照覆盖，以及多服务器和异步任务的竞态问题。compose-api 已更新，**升级后需重跑安装脚本**

### v1.3.24(2026-10-26)

- 修正「唤醒并刷新」范围:对阵列内所有已分配且状态正常的休眠盘生效,包含数据盘、校验盘、HDD 和 SSD;未分配、禁用盘及阵列外 cache/boot 不处理。修复休眠 SSD 被错误排除的问题。compose-api 已更新,**升级后需重跑安装脚本**。原生版本为 TestFlight 2.0.0(37)

### v1.3.23(2026-10-26)

- Web 与原生 iOS 新增独立「唤醒并刷新」:普通刷新读取 Unraid 已有数据,显式唤醒只针对休眠的阵列机械数据盘,经官方 `emcmd` 执行并展示逐盘结果。compose-api 新增 `diskwake`,**升级后需重跑安装脚本**。原生统一容器、VM、Compose、文件、通知与插件为点行进详情管理,移除列表省略号、长按和滑动单项操作。原生版本为 TestFlight 2.0.0(36)

### v1.3.22(2026-10-26)

- Web 与原生 iOS 总览新增阵列数据盘容量汇总,展示总容量、已用、剩余和加权使用率;原生磁盘明细默认折叠,刷新成功自动收起。修复显式刷新后休眠状态未更新,详情完成后补查轻量状态,不新增自动唤盘路径。原生版本为 TestFlight 2.0.0(35)。compose-api 无变化,无需重跑安装脚本

### v1.3.21(2026-10-26)

- 全端 App Icon 统一为 Unraid 官方红橙渐变圆形底与白色条形标志,中央叠加粗线手机 U 形,覆盖原生 iOS、Web/PWA 和 Docker;原生版本为 TestFlight 2.0.0(34)。compose-api 无变化,无需重跑安装脚本

### v1.3.20(2026-10-26)

- Web 与原生 iOS 升级 VM 控制中心:状态概览与筛选、UUID 静态详情、单 VM CPU/内存/磁盘/网络实时趋势、QEMU/libvirt 增量日志,并优化批量操作与后台恢复;原生版本为 TestFlight 2.0.0(33)。compose-api 新增 VM 指标与日志接口,**升级后需重跑安装脚本**

### v1.3.19(2026-10-26)

- Web 新增 Pro 插件管理:独立列表与详情、检查更新、单个/全部更新、HTTPS `.plg` 安装/卸载、后台进度与逐项日志;设置提供入口,总览仅在有更新时提醒。全端更新、Compose、下载与上传任务改为页内展示,普通反馈不再遮挡页面,危险确认统一 Modal;原生交互同步随 TestFlight 2.0.0(32) 发布。compose-api 与 v1.3.18 一致,无需重复重跑安装脚本

### v1.3.18(2026-10-26)

- 原生 iOS 新增 Pro 插件管理(TestFlight 2.0.0(31)):已安装列表、检查更新、单个/全部更新、HTTPS `.plg` 安装与卸载,带后台进度、逐项结果和日志;compose-api 新增串行官方 `plugin` CLI worker 与安全校验。**本次后端有更新,升级后需重跑安装脚本**

### v1.3.17(2026-10-25)

- 全端启用 Unraid 橙极简 App Icon(手机剪影 + 负形 U),统一 iOS、Web/PWA 与 Docker 视觉识别;原生一键更新新增底部可折叠任务面板与统一折叠日志(TestFlight 2.0.0(30))。compose-api 无变化,无需重跑安装脚本

### v1.3.16(2026-10-25)

- 修复 webGui 主缓存远端 digest 假最新导致的 Docker/Compose 更新漏报:后台独立复查全部当前容器镜像,新鲜补扫结果优先,主缓存仅在缺失或查询失败时回退;msgo 等已有缓存但远端长期未更新的镜像现在也能正确显示更新。**本次后端有更新,升级后需重跑安装脚本**

### v1.3.15(2026-10-25)

- 修复 Docker 与 Compose 可更新状态漏报:compose-api 后台补查主缓存未覆盖的当前容器镜像,合并补充缓存并实时比较本地镜像 digest;成功项 24 小时复查、失败项 5 分钟重试,不阻塞容器列表轮询。原生 iOS 同步发布 TestFlight 2.0.0(29)。**本次后端有更新,升级后需重跑安装脚本**

### v1.3.14(2026-10-25)

- Web 与原生 iOS 容器页新增可更新数量与一键更新:普通容器批量更新,Compose 容器按归属栈依次拉取并整栈重建,支持阶段进度、失败续跑与双列表刷新;原生页签调整为容器、Compose 栈、虚拟机(TestFlight 2.0.0(28))。compose-api 无变化,无需重跑安装脚本

### v1.3.13(2026-10-24)

- compose-api 新增轻量 `networkinfo` 接口,直读 `/proc/net/dev` 与 `/sys/class/net`,避免慢速 GraphQL 网卡查询阻塞总览其他请求;原生 iOS 总览优先使用该接口,老后端自动回退。**本次后端有更新,升级后需重跑安装脚本**。原生 iOS(TestFlight 2.0.0(26))统一共享页顶部分段、各页搜索框与加载/错误/过滤空态,并补齐小按钮点击区

### v1.3.12(2026-10-18)

- 修复共享页图片下载/预览 404:nginx 静态资源正则路由抢在 /files 前缀之前匹配,带鉴权的图片请求被错误分流,/files 与 /config/ 两个前缀路由补 ^~ 优先级修正,顺带堵住了正则块无鉴权直出图片的绕过面;/config/ 目录响应补 CSP sandbox 与 nosniff 安全头,堵住上传同源 HTML 执行读取 apiKey 的攻击面。compose-api 无变化,无需重跑安装脚本;升级需拉取新镜像并 recreate 容器(nginx 配置烤在镜像里)。原生 iOS 端(TestFlight 2.0.0(19))新增共享页相册上传与下载保存到相册

### v1.3.11(2026-10-17)

- 日志轮询改为自适应退避,省电省资源:容器日志实时刷新与系统日志自动刷新在有新内容时保持基准间隔(5 秒),无新内容(含请求失败)时指数退避至 30 秒封顶;Compose 栈操作、docker 更新等任务型轮询保持 2 秒不变。纯前端版本,compose-api 无变化,无需重跑安装脚本

### v1.3.10(2026-10-17)

- 所有日志视图统一为最新一行在顶(Web 与原生 iOS):容器日志、Compose 栈操作日志、docker 镜像更新日志;容器日志实时刷新时新行从顶部插入,不再打扰翻阅旧日志的滚动位置。已删除/重建的容器(缓存恢复的幽灵行)查看日志时显示可读中文提示,不再透传英文「No such container」。纯前端版本,compose-api 无变化,无需重跑安装脚本

### v1.3.9(2026-10-17)

- 修复容器/虚拟机停止、重启假超时:这类操作在 unraid-api 侧是阻塞型 mutation(实测约 11 秒,服务端轮询等待目标状态落定),默认 10 秒超时先于动作完成触发,UI 报失败但实际已执行成功;操作类 mutation 改用专用 60 秒超时。纯前端版本,compose-api 无变化,无需重跑安装脚本

### v1.3.8(2026-10-17)

- 总览 CPU/内存卡片优化:CPU 卡删每核细条改规格行(型号 + 核数 + 最大/当前频率),内存卡全平铺显示厂商/容量/DDR 类型/频率与已用/可用/缓存;共享页改版:文件夹/硬盘分页签,硬盘页签可看校验盘/数据盘/缓存池/启动盘(不唤醒休眠盘),磁盘与缓存池可点进只读浏览目录与下载;原生 iOS 端(App)新增冷启动快照缓存秒开、总览页顶统一搜索入口。compose-api 无变化,无需重跑安装脚本;**升级后需 recreate 容器**(新增 /mnt 只读挂载、日志挂载点由 /mnt/hostlog 改为 /hostlog)

### v1.3.7(2026-10-10)

- iOS 质感改版:全局材质与结构对齐原生 iOS——顶栏/底部标签栏毛玻璃(半透明 + 背景模糊,滚动时内容透出)、每页 34pt 大标题随滚动折叠并联动顶栏小标题(iOS Large Title)、容器/虚拟机/共享文件列表改圆角分组列表(组内细分隔线)、全站卡片统一无阴影分组风格、浅色底换 iOS 分组灰;底部抽屉与弹窗改弹簧物理动效(尊重「减弱动态效果」设置);iOS 原生壳新增触觉反馈(按钮按压/刷新完成/危险操作警示,需 TestFlight 1.3.7 及以上)。纯前端版本,compose-api 与 v1.3.6 相同,升级无需重跑安装脚本

### v1.3.6(2026-10-09)

- 容器更新进度可视化:更新容器时实时显示镜像拉取层进度与重建日志(compose-api 新增异步任务接口,前端弹窗轮询日志;操作冲突 409、无用户模板 422,不再静默)。修复:iOS 原生壳写操作(PUT)全灭报「compose-api 不可达」——nginx CORS 清单漏 PUT,补齐;/graphql 代理 60s 超时掐死长请求造成假失败——与前端超时对齐 300s。内部:前端架构重构(zustand 统一全局状态、TanStack Query 接管缓存/去重/失效、轮询统一、设置/调试/搜索大页面拆分、弹层按钮组件体系),行为不变。**compose-api 有更新**(api.php 新增 dockerupdate/dockerlog + update-status.php 单镜像模式),宿主需重跑安装脚本

### v1.3.5(2026-10-03)

- iOS 原生壳三连修复(TestFlight 实测发现,web/PWA 同源下全部潜伏):① /graphql 代理响应叠出双 ACAO 头(上游 unraid-api 自带 CORS 反射 Origin + nginx add_header),浏览器按规范拒读,表现为「unraid API 不可达」而服务端日志全 200——剥上游头,nginx 单侧出头;② /config/ 的 limit_except 内 auth_basic 抢跑把跨域预检拒成 403,原生壳下设备计数/激活留痕/settings 写回全部静默失败——改 map 变量 realm,GET/OPTIONS 免鉴权;③ 共享页 baseUrl 剥端口(web 同源遗留),原生下被 webview 当相对路径打到 capacitor://localhost——原生分支统一走 apiBase()。license 机制补账:签发登记簿(gen-license 每次签发自动登记,含完整 key 串,本地留存)+ 激活留痕(哪把 key 激活在哪台设备,服务器侧可查);apiBase 读不到服务器列表时回退 legacy 配置(全新装保存即备份不再 load failed)。纯前端版本,compose-api 与 v1.3.0~v1.3.4 相同

### v1.3.4(2026-10-03)

- iOS 原生壳(TestFlight)适配:补声明本地网络权限(iOS 对未声明的 app 静默拦截局域网请求,表现为服务器不可达);nginx CORS 全链路补齐——新增 /healthz 端点、各 API/DAV 端点 OPTIONS 预检补全 Allow-Methods/Headers(DAV 含 Range/If-Range 断点续传头)、鉴权失败 403 响应也带 CORS 头(此前跨域下连「密码错误」提示都显示不出);原生壳下载「保存」改走 Capacitor 插件写盘 + 系统分享面板(WKWebView 网页分享 API 对文件支持不全)。纯前端版本,compose-api 与 v1.3.0~v1.3.3 相同

### v1.3.3(2026-10-03)

- iOS 端文件下载根治:iPhone/iPad 的浏览器与 PWA 均不支持网页静默下载(此前下载完成后文件不落盘、无处可查),iOS 下完后浮条新增「保存」按钮,点按弹系统分享面板,选「存储到文件」自选落点(分享面板按系统要求须用户手势触发,无法自动弹);另修复部署脚本 stdin 被 ssh 截胡的隐患。纯前端版本,compose-api 与 v1.3.0~v1.3.2 相同

### v1.3.2(2026-09-30)

- 容器/VM 页签与共享根列表冷启动慢根治:挂载播种改入专用存储——只被成功刷新覆盖、永不失效(旧播种源 GraphQL namespace cache 会被容器操作/轮询失效/切服务器按设计删除,导致播种落空重新等数秒);设置 → 关于 新增构建自省(运行 vs 服务器 bundle hash,一键重载)与各页播种状态,PWA 跑旧壳可自查自愈。纯前端版本,compose-api 与 v1.3.0/v1.3.1 相同

### v1.3.1(2026-09-28)

- 共享页大文件下载根治:去掉 120 秒硬超时,改「30 秒无新数据」停滞看门狗,外网慢链路大文件不再必败;新增下载进度浮条(百分比/取消)与断点续传(失败/取消后从断点继续,文件变更自动重下);容器/VM 页签秒开(GraphQL 缓存播种,后台静默刷新)。纯前端版本,compose-api 与 v1.3.0 相同

### v1.3.0(2026-09-26)

- 仪表盘趋势改版:CPU/内存/网络 IO 默认显示「近 N 分钟采样 · 均/峰/谷」文字摘要,趋势图折叠备查(展开后为细致模式:自适应刻度轴、网格、时间标尺、按住查看单点、贝塞尔平滑曲线);网络 IO 新增收/发速率趋势;新增服务器全量备份(app-backup.json,凭 WebDAV 密码一键恢复);compose 破坏性操作确认收敛单入口;设置页高频项上移。**compose-api 有更新**:全盘索引开关收窄为只管每日自动构建、安装默认关闭自动构建——宿主需重跑安装脚本升级后端

### v1.2.9(2026-09-19)

- Webhook 通知大扩展:新增飞书群机器人 provider(可选签名密钥);事件源从仅容器扩到 12 个、逐项开关——VM 停止、UPS、奇偶校验、磁盘状态/温度、阵列启停、unRAID 原生告警转发、日志关键字、CPU/内存/CPU 温度阈值(阈值+迟滞+冷却可调)。原生告警按标题去重,6 小时窗内同标题只推一次。修复 iOS 主屏 PWA 顶栏模糊——**老用户需删除主屏图标重新添加才能生效**。纯前端版本,compose-api 与 v1.2.8 相同

### v1.2.8(2026-09-19)

- 全局搜索移动端优先:文件搜索入口提到输入框正下方;手机输入法回车键显示「搜索」,回车直搜文件(Pro 走索引,免费进唤盘确认);点搜索按钮立即弹键盘;PC 键盘提示(⌘K/Enter/↑↓/Ctrl+S)移动端隐藏。纯前端版本,compose-api 与 v1.2.7 相同

### v1.2.7(2026-09-19)

- 全盘文件索引(Pro):cron 每日定时把 `/mnt/user` 文件名索引构建到 cache 池,文件搜索直读索引——毫秒出结果、零唤盘;设置页新增卡片(开关/整点/手动重建)。文件搜索收敛单入口(Pro 走索引,免费保留实时全盘)。修复 cache-only 软链份额(`strm`、`appdata` 等)被索引/全盘搜索漏掉的问题。需在宿主更新 compose-api

### v1.2.6(2026-09-18)

- 全盘文件搜索:全局搜索新增「在全盘搜索」入口,经 compose-api `?action=search&scope=all` 扫 `/mnt/user`(cache + 全部阵列盘)——需原地二次确认(将唤醒休眠盘,可能耗时 1-2 分钟),无「记住选择」,后端审计留痕;缓存盘无结果时自动引导

### v1.2.5(2026-09-18)

- 新增全局搜索页(顶栏搜索图标):可搜索 Docker 容器、Compose 栈、虚拟机、共享目录及文件;文件搜索支持缓存盘路径(如 `.strm` 媒体库),由 compose-api 新增 `?action=search` 端点在宿主执行 `find`(带时限)
- Dockerfile 移除 nginx IPv6 监听 entrypoint 脚本(实际只监听 IPv4);规避 nginx:alpine 升级 apk-tools 3.0.8 后 `apk manifest nginx` 卡数分钟导致容器启动超时的问题

### v1.2.4(2026-08-23)

- 修复 Docker 容器与 Compose 栈的更新徽章:compose-api 新增 `?action=updates` 端点作为更新状态权威来源,绕过三个上游 unraid-api 问题(每日 digest cron 被官方禁用、GraphQL 不剥 `library/` 前缀、缓存 local digest 被冻结)——官方镜像(如 ms-go)徽章正常显示,pull/rebuild 后即时消除
- 修复 `docker images --digests` 多 RepoDigest 行干扰导致的更新误报(改为逐 ref `docker image inspect`)
- 修复日志较大的栈(如 Teslamate,>64 KB)打开详情或拉取镜像时报 "HTTP 200":日志按字节截断切断多字节 UTF-8 字符,JSON 编码失败返回空响应
- 修复 pull/rebuild 执行中详情弹窗日志区塌陷的问题

### v1.2.3(2026-08-21)

- 安全加固:`/files` 和 `/dav/` 增加 `Content-Security-Policy: sandbox` 响应头,经 WebDAV 上传的 HTML/SVG 文件被打开时不再能以 app 同源执行脚本窃取存储的 API key(闭合该提权链);图片/文本/下载预览不受影响
- `/files`、`/dav/`、`/var/log/` 三个 location 补 `X-Content-Type-Options: nosniff` 响应头

### v1.2.2(2026-08-14)

- 优化 Dashboard 卡片排序:上移/下移收进手柄弹出菜单,常态只显示拖动手柄,不再遮挡卡片标题;消除 iOS 点按后残留的半透明白框
- 优化 Dashboard 拖拽预览:拖动卡片时跟手预览为正常大小的半透明整卡(圆角+阴影),消除浏览器默认「小白框」
- 修复告警弹层「去 WebUI 查看」在 iOS PWA 打开空白页:统一改为跳 unRAID 登录页,并改用真实链接(不再 JS window.open)
- 文档:README 补充界面截图

### v1.2.1(2026-08-10)

- 修复 Shares 文件管理:`#` 文件名被 URL fragment 截断、中文重命名/移动/拷贝失败(DAV 路径统一编码)
- 修复 Shares 根目录手动刷新 30 分钟内无效
- 修复 Shares 大文件下载/预览被 15 秒超时误伤(延长至 120 秒)
- 修复 Shares 分享链接双重编码导致中文路径 404
- 修复 Safari 下文件列表日期解析可能产出 NaN
- 修复设置页服务器地址带空格/缺协议/格式非法导致保存后显示异常(统一归一化与校验)
- 修复设置页「关于」版本号长期写死的问题(改为构建注入,与 package.json 单一来源同步)

## 常见问题

**Q: 如何配置服务器？**
当前版本只管理一台 unRAID。Web 版请求始终由当前容器的 `UNRAID_UPSTREAM` 代理，
设置页中的服务器地址用于标识连接和生成宿主编排链接；原生端则直连设置页中的地址。
升级旧多服务器配置时，原生端保留原 active 服务器；Web 端只自动迁移能确认属于当前入口的配置，
无法确认时会要求在设置页重新填写，避免把旧服务器密钥发给当前上游。

**Q: 有问题去哪讨论 / 反馈?**
加 Telegram 交流群:<https://t.me/+l1iA02ZkOK1lNmEx>,或在 GitHub 提 issue。

**Q: API 连接失败?**
检查服务器地址格式(不带尾部斜杠)、API 密钥是否有效、容器到 unRAID webGui 网络是否通。

**Q: 容器详情里端口/标签/网络是空的?**
容器详情(端口/挂载/网络/磁盘占用)为免费功能;为空则是该容器本身无此配置(如 host 网络无端口映射)。

**Q: 换手机/浏览器后要重输 API 密钥?**
是。apiKey 只存浏览器 localStorage,不跟设备走;服务器端不落任何凭证。

## License 与商业化

本仓库代码以 **Business Source License 1.1(BSL)** 授权(见 [LICENSE](LICENSE)):

- ✅ 个人/家庭自托管:随意用、随意改、随意自建(不限服务器台数)
- ✅ 每个版本发布满 4 年后,该版本自动转为 MIT 完全自由许可
- ❌ 禁止转售、禁止把本软件做成收费/托管服务提供给第三方

商业化形式:代码全部公开,**Pro 功能通过 license key 解锁**(设置页输入即可,买断制)。
**1 枚 key 绑定 1 台 unRAID 服务器**(按 U 盘 flashGuid 校验;客户 key 首次激活时自动绑定,
重装系统不影响;更换 U 盘后联系重签),**最多可在 3 台设备**(手机/浏览器)上激活,
旧设备「解除绑定」即释放名额。旧格式 key 已停用;新 autoBind key 仅首次绑定需联网并上传哈希。
License 的技术性质是"防君子不防高手"——真正的商用保护由 BSL 许可证兜底。
如果你喜欢这个项目,购买 Pro 是对开发最直接的支持。
