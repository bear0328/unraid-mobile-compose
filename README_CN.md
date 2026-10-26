# unRAID Mobile — Docker Compose 部署

**[English](README.md)** | 简体中文

[![Telegram 交流群](https://img.shields.io/badge/Telegram-交流群-2CA5E0?logo=telegram&logoColor=white)](https://t.me/+l1iA02ZkOK1lNmEx)
[![Docker 镜像版本](https://img.shields.io/docker/v/bear0328/unraid-mobile?sort=semver&label=docker)](https://hub.docker.com/r/bear0328/unraid-mobile)
[![Docker Pulls](https://img.shields.io/docker/pulls/bear0328/unraid-mobile)](https://hub.docker.com/r/bear0328/unraid-mobile)

专为移动设备优化的 unRAID 服务器管理界面,以单 Docker 镜像分发
([`bear0328/unraid-mobile`](https://hub.docker.com/r/bear0328/unraid-mobile))。
数据走 unRAID GraphQL API(7.2+;磁盘休眠图标和网络速率统计需要 unRAID 7.3+ /
unraid-api ≥ 4.20,老版本会自动降级,仅缺少这两项,其余功能正常)。

> **本仓库只放部署资产**——docker-compose 文件、unRAID Docker UI 模板、可选的宿主后端
> (compose-api)。应用本体已转为**闭源**,以 Docker Hub 镜像分发,这里不需要构建任何东西。

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
| 全局搜索、命令面板、配置备份/导入、单服务器、深色主题、PWA | **容器一键更新**(单个/批量,webGui 同款)、**UPS 监控**(电量/续航/负载)、容器批量操作、**多服务器**、告警通知(Webhook)、磁盘清理 |

¹ 依赖宿主后端(compose-api)——需按下方「Pro 宿主后端」一节在 unRAID 宿主安装小组件,
**安装会修改开机脚本 `/boot/config/go`,请先知悉其中的风险说明**。免费版无需安装任何东西。

**宿主后端依赖原则**:Compose 管理 / CPU 温度均依赖宿主 `api.php`
(安装到 `/boot/config/plugins/unraid-mobile/`),此类功能均为 Pro。

Pro 为**买断制**:一次付费永久使用(含 1 年更新)。在「设置 → License」输入 key 即解锁,
全程离线验证,不联网、不上传任何数据。购买渠道见 GitHub Releases / 后续公告。

## 告警通知(Webhook)

「设置 → Webhook 通知」可把服务器事件推送到 **Bark / Telegram / Discord / 飞书(自定义机器人,
支持签名校验)/ Email(Resend)/ 自定义 HTTP**。事件源按需开启(默认仅容器停止):

- 状态变化:容器停止、VM 停止、UPS 市电变化/电池低电、奇偶校验开始/结束(有同步错误升级
  error)、磁盘状态异常、阵列启停
- 阈值告警(带迟滞 + 冷却):CPU 占用、内存占用、CPU 温度(Pro)、磁盘温度、UPS 低电量
- 其他:**unRAID 原生告警转发**(系统通知中心 ALERT/WARNING,含 SMART 等)、日志关键字命中

磁盘类事件沿用 30 分钟磁盘缓存节拍,不会因监控额外唤醒休眠盘。消息模板变量:
`{{container}} {{state}} {{server}} {{time}} {{category}}`。

## 快速开始(Docker Hub 镜像)

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

### 方式二:docker-compose

直接用本仓库现成的 [`docker-compose.yml`](docker-compose.yml):

```bash
curl -fsSL -o docker-compose.yml \
  https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/master/docker-compose.yml
# 先改 UNRAID_UPSTREAM,再:
docker compose up -d
```

### 方式三:unRAID Docker UI 模板

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

完整 docker-compose 示例:

```yaml
services:
  unraid-mobile:
    image: bear0328/unraid-mobile:latest
    container_name: unraid-mobile
    ports:
      - "3999:80"
    environment:
      - UNRAID_UPSTREAM=http://192.168.1.100:8001  # 改成你的 unRAID 地址
    volumes:
      - /mnt/user/appdata/unraid-mobile/config:/usr/share/nginx/html/config
      # 按需取消注释:
      # - /mnt/user:/mnt/user
      # - /mnt/cache:/mnt/cache
      # - /mnt:/mnt:ro  # 磁盘/缓存池只读浏览(共享页 → 硬盘页签)
      # - /var/log:/hostlog:ro
      # - /var/run/php-fpm.sock:/hostrun/php-fpm.sock
    restart: unless-stopped
```

## Pro 宿主后端(compose-api):Compose / 插件管理 / CPU 温度

Compose、插件管理和 CPU 温度(均为 Pro 功能)依赖一个宿主端小组件(`api.php`,经宿主 php-fpm
以 root 执行 `docker compose` / 官方 `plugin` CLI / 直读 `/sys/class/hwmon`)。
未安装时对应功能显示安装指引或占位,**其余功能不受影响**。
宿主后端源码完整公开在 [`compose-api/`](compose-api/),以 root 跑在你服务器上的东西,
每一行都可以自行审计。

原生端「设置 → 插件管理」支持已安装列表、手动检查更新、单个/全部更新、HTTPS `.plg` 安装与卸载。
任务在宿主后台串行执行并保留日志。插件作者提供的脚本会以 root 运行;只安装可信来源,
卸载及配置清理由插件自身定义。Community Applications 市场与 Unraid OS 更新不在此功能范围内。

> ⚠️ **风险说明(安装前必读)**
> 安装脚本会修改以下持久宿主文件(均带【unraid-mobile】标记,可完整还原):
> - `/boot/config/go`:开机脚本,追加恢复钩子(6 行),用于重启后恢复 Pro 后端组件、清理 Compose 残留锁
> - `/boot/config/plugins/unraid-mobile/api.php`:Pro 后端(Compose / CPU 温度 / VM 详情)flash 正本
> - `/boot/config/plugins/unraid-mobile/plugin-worker.php`:插件后台任务 worker flash 正本
> - `/boot/config/plugins/unraid-mobile/update-status.php`:Compose 更新徽章回写脚本 flash 正本
> - `/boot/config/plugins/unraid-mobile/apikey`:API key 的 sha256 哈希(600,明文不落 flash)
> - `/boot/config/plugins/unraid-mobile/audit.log`:api.php 关键操作审计日志
> - `/boot/config/go.unraid-mobile-bak`:安装前自动备份的 `go` 文件
>
> 保障措施:
> - 修改前自动备份 `/boot/config/go` 为 `/boot/config/go.unraid-mobile-bak`
> - 仅追加带【unraid-mobile】标记的恢复钩子,不改动你已有的任何行
> - 脚本执行时会要求输入 `YES` 显式确认
> - 卸载:删除 `/boot/config/plugins/unraid-mobile/` 和 `go` 里标记的恢复钩子即完全还原
>
> 如果你不接受对开机脚本的任何修改,不要安装 —— 免费版的全部功能都不需要它。

安装前提:已通过 Community Applications 安装 **compose.manager** 插件。

```bash
# 在 unRAID 宿主上以 root 执行
mkdir -p /tmp/um-install && cd /tmp/um-install
curl -fsSL -o install-compose-api.sh \
  https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/v1.3.21/compose-api/install-compose-api.sh
curl -fsSL -o api.php \
  https://raw.githubusercontent.com/bear0328/unraid-mobile-compose/v1.3.21/compose-api/api.php
bash install-compose-api.sh
```

脚本会:风险确认(输 YES)→ 校验 compose.manager → 交互式收 apiKey 写入
`/boot/config/plugins/unraid-mobile/apikey`(600,存 `sha256:` 哈希,明文不落 flash 盘)
→ 装 api.php 到 compose.manager 插件目录 → 备份并加 `/boot/config/go` 恢复钩子。幂等,可重复跑。

装完给容器加上 php-fpm.sock 挂载并重建,Compose tab 与 CPU 温度(Pro 激活后)即可用。

## unRAID GraphQL API 限制说明

### Docker 容器
| 功能 | 支持 | 字段 |
|------|------|------|
| 列表 | ✅ | id, names, image, state, status, autoStart, created |
| 日志 | ✅ | logs(tail) { lines { timestamp, message } } |
| 统计 | ✅ | stats { cpuPercent, memUsage, memPercent }(订阅) |
| 启停 | ✅ | mutation |
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

**Q: 添加了多台服务器,切换后数据没变或 401?**
已知限制:多服务器切换目前只更换 API 密钥,数据请求仍走当前容器的同源代理
(即部署本容器的这台 unRAID)。单服务器使用不受影响;跨机直连在路线图中。

**Q: 有问题去哪讨论 / 反馈?**
加 Telegram 交流群:<https://t.me/+l1iA02ZkOK1lNmEx>,或在 GitHub 提 issue。

**Q: API 连接失败?**
检查服务器地址格式(不带尾部斜杠)、API 密钥是否有效、容器到 unRAID webGui 网络是否通。

**Q: 容器详情里端口/标签/网络是空的?**
容器详情(端口/挂载/网络/磁盘占用)为免费功能;为空则是该容器本身无此配置(如 host 网络无端口映射)。

**Q: 换手机/浏览器后要重输 API 密钥?**
是。apiKey 只存浏览器 localStorage,不跟设备走;服务器端不落任何凭证。

**Q: 应用源码在哪?**
应用本体已闭源(原公开源码仓库已转为私有)。本仓库包含部署与审计所需的一切:
compose 文件、Docker UI 模板,以及宿主后端的完整源码——唯一在你服务器上
以容器外身份运行的组件。

## License 与商业化

- **应用本体**(Docker 镜像 `bear0328/unraid-mobile`)闭源,保留所有权利;
  个人/家庭自托管免费使用。
- [`compose-api/`](compose-api/) 下的**宿主后端**源码为便于审计而公开,以
  **Business Source License 1.1(BSL)** 授权(见 [LICENSE](LICENSE)):
  个人使用与修改自由;**禁止转售、禁止做成收费/托管服务提供给第三方**。

商业化形式:**Pro 功能通过离线 license key 解锁**(设置页输入即可,买断制,不联网验证)。
**1 枚 key 绑定 1 台 unRAID 服务器**(按 U 盘 flashGuid 校验,重装系统不影响;更换 U 盘后
联系重签),**最多可在 3 台设备**(手机/浏览器)上激活,旧设备「解除绑定」即释放名额。
如果你喜欢这个项目,购买 Pro 是对开发最直接的支持。
