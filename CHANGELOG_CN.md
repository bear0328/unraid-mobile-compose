# 更新日志

本文件记录项目的所有重要变更,格式参考 [Keep a Changelog](https://keepachangelog.com/)。

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
