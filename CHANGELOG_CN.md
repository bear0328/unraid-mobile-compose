# 更新日志

本文件记录项目的所有重要变更,格式参考 [Keep a Changelog](https://keepachangelog.com/)。

## [1.3.15] - 2026-10-25

### 修复

- 修复 webGui 更新缓存未覆盖部分 Compose 镜像导致的永久漏报:后台枚举当前容器镜像并补查远端 manifest digest,与主缓存合并后实时比较本地 digest;补充缓存成功项 24 小时复查、失败项 5 分钟重试,支持并发锁与原子写入,HTTP 轮询不同步等待 Registry。**升级后需重跑安装脚本**

## [1.3.14] - 2026-10-25

### 新增

- Web 与原生 iOS 容器页新增可更新数量与一键更新:普通容器批量更新,Compose 管理容器按归属栈依次执行拉取与整栈重建,避免重复更新;支持阶段进度、失败续跑和完成后的双列表状态刷新
- 原生 iOS(TestFlight 2.0.0(28))容器页分段顺序调整为容器、Compose 栈、虚拟机,让关联功能相邻

## [1.3.13] - 2026-10-24

### 新增

- compose-api 新增轻量 `networkinfo` 接口,直读 `/proc/net/dev` 与 `/sys/class/net`,不执行外部命令、不碰块设备;原生 iOS 总览优先走该接口,避免慢速 GraphQL 网卡查询拖住核心请求,老后端自动回退。**升级后需重跑安装脚本**

### 变更

- 原生 iOS(TestFlight 2.0.0(26)):共享页文件夹/硬盘分段固定页顶;容器、目录和系统日志搜索区统一常显;补齐首载、中文过滤空态与日志错误态优先级;下载、上传和告警小按钮补齐 44pt 点击区

## [1.3.12] - 2026-10-18

### 修复

- 共享页图片下载/预览 404:nginx 静态资源正则路由(`~ \.(jpg|png|...)$` 类)按匹配顺序抢在 /files 前缀路由之前命中,带鉴权的图片请求被分流到无鉴权的静态块;为 /files 与 /config/ 两个 prefix location 补 `^~` 优先级修饰符,顺带堵住了正则块无鉴权直接出图的安全绕过面
- /config/ 目录响应补 `Content-Security-Policy: sandbox` 与 `X-Content-Type-Options: nosniff` 安全头,堵住经备份/恢复通道上传同源 HTML 后执行脚本读取 apiKey 的攻击面(消费方均为 fetch/DAV 调用,无文档导航,sandbox 语义安全)

## [1.3.11] - 2026-10-17

### 变更

- 日志轮询改为自适应退避,省电省资源:容器日志实时刷新与系统日志自动刷新在有新内容时保持 5 秒基准间隔,无新内容(含请求失败)时指数退避至 30 秒封顶,有新内容立即恢复;Compose 栈操作、docker 镜像更新等任务型轮询保持 2 秒不变。系统日志页的裸 setInterval 轮询顺带收编进统一轮询基建

## [1.3.10] - 2026-10-17

### 变更

- 所有日志视图统一为最新一行在顶(Web 与原生 iOS):容器日志、Compose 栈操作日志、docker 镜像更新日志。容器日志实时刷新时新行改为从顶部插入(不再尾部追加),翻阅旧日志时滚动位置不再被新内容推走;增量轮询与边界去重逻辑保持不变,仅镜像了合并方向

### 修复

- 查看已删除/重建容器(本地快照缓存恢复的幽灵行)的日志时,不再透传服务端英文原文「No such container」,映射为可读中文提示;Web 端列表刷新后幽灵行自愈

## [1.3.9] - 2026-10-17

### 修复

- 容器/虚拟机的停止、重启不再假超时:这类操作在 unraid-api 侧是阻塞型 mutation(实测约 11 秒,服务端会轮询等待目标状态落定),默认 10 秒请求超时会先于动作完成触发——Web 端报失败,但容器其实已停止/重启。操作类 mutation 现改用专用 60 秒超时(`graphql.ts` 新增 `ACTION_TIMEOUT`,应用于 docker stop/restart 与 VM stop/reboot)

## [1.3.8] - 2026-10-17

### 新增

- 总览卡片优化:CPU 卡删每核细条改规格行(型号 + N 核心 + 最大/当前 X.XX GHz,speedmax 缺失回退当前频率、上游抖动防抖),内存卡去展开全平铺——规格行(厂商裁短归一化 + 容量 + DDR 类型 + 频率,空槽过滤/混插取最低)+ 占用行常驻已用/可用/缓存;新增字段查询失败时自动剥离该字段重试(兼容老版本 unraid-api,取代静态降级查询)
- 共享页改版:文件夹/硬盘分页签;硬盘页签三层数据(校验盘/数据盘带温度与休眠状态、缓存池/启动盘经专用 POOLS_QUERY 直取——实测不唤醒休眠阵列盘),磁盘与缓存池可点进只读目录浏览(新增 /mnt 只读挂载 + nginx autoindex,Range 断点续传下载可用,非 user 根下写操作/上传/编辑/分享链接全隐藏);顺带修复点进 diskN 伪共享必 404
- iOS 原生端(App):冷启动快照缓存(按服务器隔离的 stale-while-revalidate,冷启动秒出缓存数据、后台照常刷新,六路页面接入)、总览页顶全局搜索胶囊入口(与容器页搜索抽屉排布统一)
- Web/iOS 双端网络区块只显示主网卡(br0/eth/bond 优先级),采样口径同步收窄

### 变更

- 容器编排:新增 `/mnt:/mnt:ro` 只读挂载(硬盘页签浏览用;ro 不传播新增子挂载,新加盘/池需 recreate 才可见),宿主日志挂载点由 `/mnt/hostlog` 改为 `/hostlog`(ro 父挂载下 docker 建不出子挂载点)。**升级后需 recreate 容器**;compose-api 无变化,无需重跑安装脚本

## [1.3.7] - 2026-10-10

### 新增

- iOS 质感改版:毛玻璃顶栏/标签栏(半透明 + 背景模糊,滚动透出)、iOS Large Title 折叠导航(34pt 页标题随滚动收缩,顶栏小标题交叉淡入)、容器/VM/共享文件列表改 iOS 圆角分组列表(hairline 分隔行)、全站卡片统一 grouped 无阴影风格、浅色底换 iOS 分组灰 #F2F2F7
- 弹簧动效:底部抽屉上滑/下滑与居中弹窗改物理弹簧曲线(WAAPI 离线采样,零依赖,prefers-reduced-motion 自动回退原 CSS 动画)
- iOS 原生壳触觉反馈:按钮按压、手动刷新完成、破坏性操作确认(@capacitor/haptics;Web/PWA 端为空操作,需 TestFlight 1.3.7 及以上)

## [1.3.6] - 2026-10-09

### 新增

- 容器更新进度可视化:更新容器时实时显示镜像拉取层进度与重建日志(compose-api 新增异步任务接口,前端弹窗 2s 轮询日志;操作冲突返回 409、无用户模板返回 422,不再静默)

### 修复

- iOS 原生壳写操作(更新容器等 PUT 请求)全部报「compose-api 不可达」:nginx CORS Allow-Methods 清单漏 PUT,跨域预检被拒——补齐(GET 此前一直正常,极具迷惑性)
- 更新大镜像时前端报失败但服务端实际更新成功:/graphql 代理 60s 超时掐断同步长请求——proxy 超时与前端超时对齐 300s

### 内部

- 前端架构重构(行为不变,为后续迭代打底):zustand 统一全局状态(收敛 8 套手写订阅器)、TanStack Query 接管数据缓存/请求去重/失效(手写 localStorage 缓存层整体删除)、轮询逻辑收敛为单一 hook、设置/调试/全局搜索三个超大页面拆分、弹层与按钮统一组件体系

**compose-api 有更新**(api.php 新增 dockerupdate/dockerlog 接口 + update-status.php 支持单镜像模式):宿主需重跑安装脚本升级后端

## [1.3.5] - 2026-10-03

### 修复

- 原生壳启动健康检查 graphql 探针「Load failed」:上游 unraid-api 自带 CORS(反射 Origin + Allow-Credentials),与 nginx add_header 叠出双 Access-Control-Allow-Origin,规范多值即非法——代理层剥掉上游 CORS 头,nginx 单侧出头(服务端日志全程 200,表象与防火墙拦截一模一样)
- 原生壳 /config/ 全部读写静默失败(设备计数/激活留痕/settings.json 写回):limit_except 内 auth_basic 抢在 OPTIONS 短路前把跨域预检拒成 403——改 map 变量 realm(GET/OPTIONS 免鉴权,其余仍需 DAV 密码)
- 原生壳共享页「未获取到共享列表」:useShares 构造 API 服务时剥掉 serverUrl 端口(web 同源时代遗留),webview 把裸 host 当相对路径打到 capacitor://localhost——原生分支统一走 apiBase()
- apiBase() 在多服务器列表为空时回退 legacy 配置(续 144:全新装保存 WebDAV 密码即备份 load failed)

### 新增

- license 签发登记簿:gen-license 每次签发自动登记 .license-registry.jsonl(gitignored,含完整 key 串,本地留存)
- license 激活留痕:激活时自动登记 /config/license-activations.json(哪把 key 在哪台设备——email + key 尾 8 位 + deviceId + 首末次时间,尽力而为)

## [1.3.4] - 2026-10-03

### 修复

- iOS 原生壳(TestFlight)连不上局域网服务器:Info.plist 补 NSLocalNetworkUsageDescription,未声明时 iOS 静默拦截局域网请求
- 原生壳(capacitor://localhost 源)跨域处处 CORS 失败:nginx 新增 /healthz 端点;OPTIONS 预检 204 补全 Allow-Methods/Headers(if 块内 add_header 不继承外层);/dav/ 预检放行 Range/If-Range(断点续传)并暴露 ETag/Content-Range;鉴权失败 403 兜底也带 ACAO;/files 的 OPTIONS 短路移到 rewrite break 之前(否则被跳过落到鉴权 403)
- iOS 原生壳下载「保存」失败:WKWebView 的 Web Share API 对文件支持不全,改 Capacitor Filesystem 逐块写 Cache + Share 插件弹系统面板(分块 base64,避免整文件转巨型字符串撑爆内存)

纯前端版本,compose-api 与 v1.3.0~v1.3.3 相同

## [1.3.3] - 2026-10-03

### 新增

- iOS 端下载完成后浮条新增「保存」按钮:弹系统分享面板,可选「存储到文件」自选落点,取消后数据保留可再点

### 修复

- iOS 文件下载静默不落盘:iPhone/iPad 各浏览器与 PWA 均无网页下载管理器,此前下载完成的文件无处可查;按系统约束分享面板须用户手势触发,故采用「下完 → 点保存」两步交互
- 部署脚本:IFS 换行 + eval 会把多参数拼成多条命令,导致 ssh 以无命令交互模式运行并截胡管道 stdin(实测把喂给确认门的 yes 当远端命令执行);ssh 一律 -n 隔离 stdin,retry 弃用 eval

纯前端版本,compose-api 与 v1.3.0~v1.3.2 相同

## [1.3.2] - 2026-09-30

### 新增

- 设置 → 关于 新增构建自省:显示运行中 bundle hash 与服务器最新 hash(不一致时「检查更新」一键重载——PWA 跑旧壳可自查自愈),并附各页播种状态诊断行

### 修复

- 容器/VM 页签冷启动仍慢:挂载播种的数据源(GraphQL namespace cache)会被容器操作、轮询失效、切换服务器按设计整个删除,播种落空后只能等数秒的真实查询;播种改为写入专用存储——只被成功刷新覆盖、永不失效、按 serverUrl 隔离
- 共享页根列表冷启动偏慢(同一根因)——同款专用播种修复(stale-while-revalidate;唤盘语义不变,不新增请求)

纯前端版本,compose-api 与 v1.3.0/v1.3.1 相同

## [1.3.1] - 2026-09-28

### 新增

- 文件下载进度浮条:实时显示已传量/总量/百分比,可取消;失败或取消后支持断点续传(HTTP Range + If-Range 校验,文件中途变更会自动从头重下,不会拼出坏文件)

### 修复

- 共享页大文件下载中途停滞:120s 硬超时改为 30s 停滞看门狗(总时长不限)+ 断点续传(Range / If-Range),弱网大文件可续
- 容器/VM 页签打开要数秒:页面挂载时从 GraphQL cache 播种秒开(stale-while-revalidate),后台静默刷新

纯前端版本,compose-api 与 v1.3.0 相同

## [1.3.0] - 2026-09-26

### 新增

- 服务器全量备份:一键导出/恢复 `app-backup.json`(含全部设置与凭证),PWA 重装/换机后凭 WebDAV 密码一键恢复
- 网络 IO 卡新增收/发速率趋势与摘要(主网卡口径,与卡片大数字一致)

### 变更

- 仪表盘趋势展示改版:CPU/内存/网络默认显示「近 N 分钟采样 · 均/峰/谷」文字摘要,趋势图折叠备查;展开后的趋势图升级细致模式——自适应刻度轴(对齐 5 的倍数、百分比钳制 0-100)、网格线、时间标尺、按住拖动/鼠标悬停查看单点值、贝塞尔平滑曲线
- compose 破坏性操作确认收敛为单入口(确认条为唯一执行口)
- 设置页区块重排:高频项在上
- 全盘文件索引:开关语义收窄为只管「每日自动构建」且安装默认关闭(手动重建任何时刻可用)——**compose-api 有更新,宿主需重跑安装脚本升级后端**
- 部署脚本清理:删除失效的 `deploy-dev.sh` 与 `deploy-stable.sh --mode dev` 镜像流程(compose 跑另一 tag,必空转);`build.sh` 移除无消费方的镜像/tar 构建

### 修复

- 磁盘温度历史行:温度平稳时不再显示无意义的「42–42°C」区间,只显示单值
- 修复 staleness 徽章(「·3h 前」)在窄屏折成两行
- 修复 `deploy-stable.sh --dry-run` 仍真实部署的 bug(dry-run 现在只打印)

## [1.2.9] - 2026-09-19

### 新增

- Webhook 通知大扩展:新增飞书群机器人 provider(可选 HMAC-SHA256 签名密钥);事件源从仅容器扩到 12 个,设置页逐项开关——VM 停止、UPS 市电/低电、奇偶校验开始/结束、磁盘状态、磁盘温度、阵列启停、unRAID 原生告警转发(webGui 铃铛同源,含 SMART)、日志关键字、CPU 占用、内存占用、CPU 温度;阈值类事件带可调阈值/迟滞/冷却,消息模板新增 `{{category}}` 变量

### 优化

- unRAID 原生告警转发按标题去重:同标题告警(磁盘温度抖动、Fix Common Problems 每日扫描等)6 小时窗口内只推一次(窗口时长设置页可调),不再每次出现都推

### 修复

- 修复 iOS 主屏 PWA 顶栏(logo/铃铛/徽章)模糊而 Safari 清晰的问题:根因是 `apple-mobile-web-app-status-bar-style=black-translucent` 让 PWA webview 伸到 iOS 状态栏下方,触发 iOS 系统级模糊,且该配置被 iOS 快照进主屏图标,仅更新应用无法生效;现已改为 `black`。**老用户需删除主屏图标,到 Safari 重新「添加到主屏幕」才能应用修复**

## [1.2.8] - 2026-09-19

### 变更

- 全局搜索移动端优先:文件搜索入口提到输入框正下方(原来沉在结果列表最底部);输入框带 `enterKeyHint="search"`,手机输入法回车键直接显示「搜索」;输入关键词后直接回车即搜文件(Pro 走索引,免费版进唤盘二次确认);按过方向键再回车仍是打开高亮项
- 实时全盘搜索链接写明后果(「没搜到?实时全盘搜索(唤醒休眠硬盘,约 1-2 分钟)」)

### 修复

- 修复手机点搜索按钮键盘不弹、要再点一次的问题——输入框改为在点击手势内同步聚焦(原来在 rAF 回调里聚焦,被移动端浏览器拦截)
- PC 键盘提示不再出现在移动端:全局搜索/命令面板底部栏、文本编辑器「Ctrl+S · Esc」状态行改为仅桌面显示;命令面板 placeholder 不再显示「Ctrl+Shift+P」

## [1.2.7] - 2026-09-19

### 新增

- 全盘文件索引(Pro):宿主每天定时(cron,缺省 03:00)把 `/mnt/user` 的文件名索引构建到 cache 池;全局搜索查文件直读索引,毫秒出结果、零唤盘。设置页新增「全盘文件索引」卡片:开关、每日构建整点(0-23)、索引统计(条数/体积/构建时间)、手动重建按钮。需在宿主更新 compose-api(安装脚本新增 `update-file-index.sh` + `register-index-cron.sh`;旧版后端会提示升级)

### 变更

- 全局搜索的文件搜索收敛为单入口:Pro 用户直走索引(零唤盘);免费用户保留 v1.2.6 的实时全盘搜索(含唤盘二次确认)并显示解锁引导。「只搜缓存盘」档从前端移除(后端 `scope=cache` 保留兼容 v1.2.6)
- 全盘文件索引为 Pro 功能(设置页卡片与索引搜索均有 Pro 门)

### 修复

- 修复 `/mnt/user` 下以软链形式存在的 cache-only 份额(如 `strm`、`appdata`、`isos`)被索引构建和实时全盘搜索整体漏掉的问题——`find` 默认不跟随软链;两者现都显式跟随顶层份额软链

## [1.2.6] - 2026-09-18

### 新增

- 全盘文件搜索:全局搜索新增「在全盘搜索」入口(缓存盘入口下方),经 compose-api `?action=search&scope=all` 扫 `/mnt/user`(cache + 全部阵列盘的 FUSE 联合视图);点击后需原地二次确认(明确警告将唤醒休眠盘、可能耗时 1-2 分钟),关键词一变确认自动失效,不提供「记住选择」,后端对每次全盘搜索写审计日志。需在宿主更新 compose-api(旧版后端会提示升级)
- 缓存盘搜索无结果时引导改用全盘搜索

## [1.2.5] - 2026-09-18

### 新增

- 全局搜索(Cmd+K)扩展数据源:VM、根分享、Compose 栈(全部只读现有缓存,绝不新发请求);结果分组显示 + 最近搜索历史;模糊匹配支持英文/拼音首字母别名(如 `docker` 命中「容器/VM」、`rz` 命中「日志」)
- 缓存盘文件搜索:全局搜索底部显式触发「在缓存盘文件中搜索」,走 compose-api 新端点 `?action=search`(宿主 find 仅扫 `/mnt/cache`,不碰阵列盘、不唤醒休眠盘);结果深链跳转 Shares 页。需在宿主更新 compose-api(安装脚本已更新;旧版后端会提示升级)
- 容器管理页新增页内搜索框:一个输入框同时过滤 Docker 容器(名称/镜像/状态)、虚拟机、Compose 栈
- iOS App 套壳(Capacitor 8,`ios/` 同仓库;原生模式 API 路径自动前缀当前服务器地址,Web 端行为零变化)。模拟器冒烟通过;真机签名需用户手动操作

## [1.2.4] - 2026-08-23

### 修复

- 修复 Docker 容器与 Compose 栈的更新徽章:compose-api 新增 `?action=updates` 端点作为更新状态权威来源,绕过三个上游 unraid-api 问题(每日 digest cron 被官方禁用、GraphQL 不剥 `library/` 前缀、缓存 local digest 被冻结)——官方镜像(如 ms-go)徽章正常显示,pull/rebuild 后即时消除
- 修复 `docker images --digests` 多 RepoDigest 行干扰导致的更新误报(改为逐 ref `docker image inspect`)
- 修复日志较大的栈(如 Teslamate,>64 KB)打开详情或拉取镜像时报 "HTTP 200":日志按字节截断切断多字节 UTF-8 字符,JSON 编码失败返回空响应
- 修复 pull/rebuild 执行中详情弹窗日志区塌陷的问题

## [1.2.3] - 2026-08-21

### 安全

- 文件访问端点 `/files` 和 `/dav/` 增加 `Content-Security-Policy: sandbox` 响应头:经 WebDAV 上传的 HTML/SVG 文件被打开时不再能以 app 同源身份执行脚本,无法读取 localStorage 中存储的 API key(该 key 持有 GraphQL 与 compose-api 全部权限);图片/文本/下载预览不受影响
- `/files`、`/dav/`、`/var/log/` 三个 location 补 `X-Content-Type-Options: nosniff`(这些 location 有自有 `add_header`,此前 server 级 nosniff 不被继承)

## [1.2.2] - 2026-08-14

### 优化

- Dashboard 卡片排序:上移/下移按钮收进手柄弹出菜单,常态只显示拖动手柄(100px → 36px),不再遮挡卡片标题;键盘方向键与 32px 触控面积保留
- Dashboard 拖拽预览:拖动卡片时跟手预览改为正常大小的半透明整卡(圆角+阴影),不再是浏览器默认的小手柄快照「小白框」

### 修复

- 排序控件在 iOS 点按后不再残留半透明背景(粘性 `:hover`);改为 active 即时反馈 + 键盘焦点环
- 告警弹层「去 WebUI 查看」在 iOS PWA 打开空白页(JS `window.open` 在 PWA 不可靠,且深链需要 webGui 登录态)—— 所有 WebUI 入口统一改为真实 `<a target="_blank">` 链接跳 `{serverUrl}/login`
- 文档:README 截图占位替换为实际应用截图

## [1.2.1] - 2026-08-10

### 修复

- Shares 文件管理:`#` 文件名被 URL fragment 截断;中文重命名/移动/拷贝失败(MOVE/COPY 的 `Destination` 头非 ASCII 抛错)—— DAV 路径在所有出口统一编码
- Shares 根目录手动刷新在 30 分钟缓存窗口内无效(刷新前先失效对应缓存命名空间)
- Shares 大文件下载/预览被 15 秒默认 DAV 超时误伤(整文件读取延长至 120 秒)
- Shares 分享链接双重编码导致中文路径 404
- Safari 下文件列表日期解析可能产出 NaN(autoindex 日期不再依赖 `Date.parse` 的本地化行为)
- 设置页:服务器地址协议后带空格/缺协议/格式非法时原样保存导致异常 —— 所有保存路径统一归一化与校验
- 设置页「关于」版本号长期写死;改为构建期从 `package.json` 注入
- `release.sh` 发版时同步 `package.json` 的 version 字段

### 已知限制

- 多服务器切换只更换 API 密钥;数据请求仍走当前容器的同源代理(即部署本容器的这台 unRAID)。单服务器使用不受影响。

## [1.2.0] - 2026-08-09

### 新增

- VM 增强详情(CPU/内存/磁盘/网络/直通/快照,读取 libvirt XML)
- Dashboard 告警铃铛本地告警列表(unRAID 通知可在应用内查看)

### 修复

- PWA 缓存根治:Service Worker 按构建 hash 版本化 + no-cache 头 + 更新后自动刷新 —— iOS PWA 不再卡在旧 bundle
- Dashboard 修复:手动刷新缓存失效口径、趋势图标签、阵列使用率按容量加权、收藏触控面积、错误 banner 重试按钮
- 容器页签修复:刷新按钮禁用态、缓存失效口径统一、VM 深链高亮滚动
