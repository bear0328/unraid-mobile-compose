# Changelog

All notable changes to this project are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/).

## [1.3.22] - 2026-10-26

### Changed

- Web and native iOS Dashboard now summarize array data-disk capacity, used space, free space, and weighted utilization. Native disk details are collapsed by default, can be expanded on demand, and collapse again after a successful refresh. Native changes ship in TestFlight 2.0.0 (35)

### Fixed

- Fixed stale sleeping indicators after an explicit disk refresh. A non-waking lightweight status query now runs after disk details complete, so the latest sleeping/spinning state wins. compose-api is unchanged, so no install-script rerun is needed

## [1.3.21] - 2026-10-26

### Changed

- Unified the app icon across native iOS, Web/PWA, and Docker: the official Unraid red-orange gradient circle and white bar mark now include a bold phone-shaped U overlay. Native changes ship in TestFlight 2.0.0 (34). compose-api is unchanged, so no install-script rerun is needed

## [1.3.20] - 2026-10-26

### Added

- Web and native iOS add VM status overview/filtering, UUID-based static configuration, per-VM CPU/host RSS/Guest memory/disk/network trends, and incremental QEMU/libvirt logs with filtering, pause, and export. Native changes ship in TestFlight 2.0.0 (33)
- compose-api adds `vmstats` raw counters and `vmlog` byte-cursor reads; `vminfo` supports UUIDs and exposes firmware, machine type, template OS, and actual disk usage. **Re-run the install script after upgrading**

### Changed

- Improved VM batch-action skipping, per-item results, unified refreshes, stale-data visibility, foreground recovery, counter resets, and late-response handling while preserving the 60-second VM-list polling floor

## [1.3.19] - 2026-10-26

### Added

- Web adds Pro plugin management with dedicated lists/details, update checks, single/batch updates, HTTPS `.plg` install/removal, inline background jobs, per-item results, collapsible logs, and job reattachment. Settings shows counts and Dashboard only displays update reminders without adding background polling. compose-api is unchanged from v1.3.18, so no install-script rerun is needed

### Changed

- Unified task and dialog interactions across Web and native iOS: container, Compose, plugin, download, and upload jobs now show inline progress and logs; ordinary results appear in the page flow, destructive actions use native alerts or a unified Modal, and custom floating task cards, browser confirm/alert calls, and hand-written modal shells were removed. Native interactions ship in TestFlight 2.0.0 (32)

## [1.3.18] - 2026-10-26

### Added

- Native iOS (TestFlight 2.0.0 (31)) adds Pro plugin management: installed-plugin lists, manual update checks, single/batch updates, HTTPS `.plg` installation, and removal, with confirmations, search, background progress, per-item results, collapsible logs, and job reattachment
- compose-api adds plugin endpoints and a dedicated background worker. All writes serially invoke the official Unraid `plugin` CLI with URL/name validation, Min/Max compatibility checks, auditing, failure continuation, compose.manager removal protection, and backend self-recovery after its update. **Re-run the install script after upgrading**

## [1.3.17] - 2026-10-25

### Changed

- Introduced a minimal Unraid-orange app icon across iOS, Web/PWA, and Docker, combining a phone silhouette with a negative-space U; added 192px/512px PNGs, Apple home-screen artwork, and offline caching
- Native iOS (TestFlight 2.0.0 (30)) moves one-click updates into a collapsible bottom task panel with per-stack and standalone progress, results, and error summaries; single-container, Compose, and batch-update logs now share collapsible, internally scrolling, newest-first views

## [1.3.16] - 2026-10-25

### Fixed

- Fixed update omissions for images already present in the primary cache when its remote digest became stale. The background scan now independently rechecks all current images instead of only cache omissions. Fresh scan results take precedence, while stale or failed checks continue to fall back to the primary cache, preventing images such as msgo from being incorrectly reported as up to date. **Re-run the install script after upgrading**

## [1.3.15] - 2026-10-25

### Fixed

- Fixed permanent update-status omissions for Compose images absent from the webGui cache. A background scan now enumerates current container images, fetches missing remote manifest digests, merges the supplemental cache, and compares live local digests. Successful checks refresh after 24 hours while failures retry after 5 minutes; locking and atomic writes are included, and HTTP polling never waits synchronously on registries. **Re-run the install script after upgrading**

## [1.3.14] - 2026-10-25

### Added

- Added update-available counts and one-click updates to the Web and native iOS Containers pages. Standalone containers update in one batch, while Compose-managed containers are grouped by stack and pulled/rebuilt sequentially without duplicate updates; includes stage progress, per-stack failure continuation, and synchronized list refreshes
- Native iOS (TestFlight 2.0.0 (28)) now orders the Containers segments as Containers, Compose, and VMs so related views stay adjacent

## [1.3.13] - 2026-10-24

### Added

- Added a lightweight compose-api `networkinfo` endpoint that reads `/proc/net/dev` and `/sys/class/net` directly without executing external commands or touching block devices. The native iOS Dashboard prefers this endpoint so slow GraphQL network queries no longer block core requests, with automatic fallback for older hosts. **Re-run the install script after upgrading**

### Changed

- Native iOS (TestFlight 2.0.0 (26)): keep the Shares folder/disk segmented control fixed at the top; make container, directory, and system-log search fields consistently visible; add first-load, Chinese filter-empty, and corrected log-error states; bring download, upload, and alert buttons up to 44pt hit targets

## [1.3.12] - 2026-10-18

### Fixed

- Image downloads/previews on the Shares page returned 404: the nginx static-asset regex route matched before the /files prefix route, diverting authenticated image requests into the unauthenticated static block; the /files and /config/ prefix locations now use the `^~` priority modifier, which also closes a security bypass where the regex block served images without auth
- Responses under /config/ now carry `Content-Security-Policy: sandbox` and `X-Content-Type-Options: nosniff` headers, closing an attack surface where a same-origin HTML file uploaded via the backup/restore channel could execute scripts and read the apiKey (all consumers use fetch/DAV calls with no document navigation, so sandbox semantics are safe)

## [1.3.11] - 2026-10-17

### Changed

- Log polling is now adaptive to save battery and server resources: container log live-refresh and the system log auto-refresh keep their 5s base interval while new lines arrive, and double the interval up to a 30s cap when nothing changes (failed requests also back off); the cadence resets to the base interval as soon as new content appears. Short-lived operation polls (compose stack actions, docker image updates) are unchanged at 2s. The system log page's raw setInterval polling was folded into the shared poll-loop infrastructure

## [1.3.10] - 2026-10-17

### Changed

- All log views now show the newest line on top (Web and native iOS): container logs, compose stack operation logs, and docker image update logs. Live container log refreshes prepend new lines at the top instead of appending at the bottom, so reading older lines is no longer disturbed by incoming output. The same cursor-based incremental polling and boundary de-duplication are kept, with the merge direction mirrored

### Fixed

- Viewing logs of a container that was deleted or rebuilt (a ghost row restored from the local snapshot cache) showed the raw server error "No such container"; it is now mapped to a readable message. The list refresh self-heals such rows on the Web

## [1.3.9] - 2026-10-17

### Fixed

- Container and VM stop/restart actions no longer report false timeouts: these are blocking mutations on the unraid-api side (measured at ~11s while the server polls for the target state), so the 10s default request timeout fired before the action finished — the Web UI showed a failure even though the container had actually stopped or restarted. Action mutations now use a dedicated 60s timeout (`ACTION_TIMEOUT` in `graphql.ts`, applied to docker stop/restart and VM stop/reboot)

## [1.3.8] - 2026-10-17

### Added

- Dashboard card redesign: the per-core CPU bars are replaced by a spec line (model + N cores + max/current frequency, falling back to the current frequency when the max is unavailable, with de-bouncing for flaky upstream values); the memory card drops its expander and always shows a spec line (normalized vendor + capacity + DDR type + speed, empty slots filtered, lowest speed wins on mixed kits) plus a persistent used/available/cached row; queries that fail on unknown fields now strip those fields and retry automatically (compatible with older unraid-api versions, replacing static fallback queries)
- Shares overhaul: folders and disks split into separate tabs; the disks tab merges three data layers (parity/data disks with temperature and spin state, cache pools and the boot drive fetched via a dedicated POOLS_QUERY — verified not to wake spun-down array disks); disks and pools can be opened for read-only directory browsing (new read-only /mnt mount + nginx autoindex, ranged/resumable downloads, write operations/upload/edit/share links hidden outside the user root); fixes the 404 when tapping diskN pseudo-shares
- Native iOS app: cold-start snapshot cache (per-server stale-while-revalidate, cached data renders instantly on launch while refreshing in the background, wired into six pages) and a capsule search entry at the top of the Dashboard (aligned with the Containers page search drawer)
- Web and iOS both show only the primary NIC in the network section (br0/eth/bond priority) with matching sampling scope

### Changed

- Container compose: new `/mnt:/mnt:ro` read-only mount (for the disks tab; read-only mounts do not propagate new sub-mounts — recreate the container after adding disks/pools), and the host log mount moved from `/mnt/hostlog` to `/hostlog` (docker cannot create sub-mount points under a read-only parent). **Recreate the container after upgrading**; compose-api is unchanged, no need to re-run the install script

## [1.3.7] - 2026-10-10

### Added

- iOS look-and-feel overhaul: frosted-glass top bar and tab bar (translucent with backdrop blur), iOS Large Title collapsible navigation (34pt page title shrinks on scroll while the nav bar title cross-fades in), container/VM/file lists rebuilt as iOS rounded grouped lists with hairline separators, all cards unified to a flat grouped style, light background switched to the iOS grouped gray #F2F2F7
- Spring animations: bottom sheets and centered modals now animate with physics-based springs (offline-sampled WAAPI keyframes, zero dependencies, automatic fallback to the original CSS animations under prefers-reduced-motion)
- Haptic feedback on the iOS native shell: button taps, manual refresh completion, destructive-action confirmations (@capacitor/haptics; no-op on Web/PWA, requires TestFlight 1.3.7 or later)

## [1.3.6] - 2026-10-09

### Added

- Container update progress visualization: updating a container now shows live image-pull layer progress and rebuild logs (new async job API in compose-api, frontend log modal polls every 2s; conflicts return 409 and missing user templates return 422 instead of failing silently)

### Fixed

- All write operations (container updates and other PUT requests) failed on the iOS native shell with "compose-api unreachable": the nginx CORS Allow-Methods list omitted PUT, so cross-origin preflights were rejected — the list is now complete (GETs worked the whole time, which made this highly misleading)
- Updating large images reported failure in the UI while the server actually succeeded: the /graphql proxy's 60s timeout cut off long synchronous requests — proxy and frontend timeouts are now aligned at 300s

### Internal

- Frontend architecture refactor (no behavior change, groundwork for future iterations): zustand unifies global state (8 hand-rolled pub/sub implementations consolidated), TanStack Query takes over data caching/request dedup/invalidation (the hand-written localStorage cache layer is removed entirely), polling logic converged into a single hook, the oversized Settings/Debug/GlobalSearch pages split up, and a unified modal/button component system

**compose-api updated** (api.php gains dockerupdate/dockerlog endpoints; update-status.php gains single-image mode): re-run the install script on the host to upgrade the backend

## [1.3.5] - 2026-10-03

### Fixed

- Native-shell startup health check failed with "Load failed" on the graphql probe: upstream unraid-api is CORS-aware (reflects Origin + Allow-Credentials) and its headers stacked with nginx's add_header into duplicate Access-Control-Allow-Origin values, which are invalid per spec — upstream CORS headers are now stripped at the proxy so nginx is the single source (server logs showed 200s the whole time, making this look exactly like a firewall block)
- All /config/ reads and writes silently failed on the native shell (device counting, activation trail, settings.json write-back): auth_basic inside limit_except ran ahead of the OPTIONS short-circuit and rejected cross-origin preflights with 403 — replaced with a map-driven variable realm (GET/OPTIONS exempt, everything else still requires DAV credentials)
- Shares page showed "未获取到共享列表" on the native shell: useShares stripped the port from baseUrl (a same-origin-era leftover), so the webview resolved the endpoint as a relative path against capacitor://localhost — the native branch now uses apiBase()
- apiBase() now falls back to the legacy config key when the multi-server list is empty (fresh installs could not save/back up the WebDAV password — session 144)

### Added

- License issuance registry: gen-license auto-appends every signed key to .license-registry.jsonl (gitignored, full key string kept locally)
- License activation trail: the app records /config/license-activations.json on activation (which key on which device — email + key tail + deviceId + first/last seen, best-effort)

## [1.3.4] - 2026-10-03

### Fixed

- iOS native shell (TestFlight) could not reach LAN servers: Info.plist now declares NSLocalNetworkUsageDescription — without it iOS silently blocks local-network requests
- Cross-origin failures everywhere from the native shell (capacitor://localhost origin): nginx gains an explicit /healthz endpoint; OPTIONS preflight 204s now carry full Allow-Methods/Headers (add_header inside an if block does not inherit the outer ones); /dav/ preflight allows Range/If-Range (resumable downloads) and exposes ETag/Content-Range; auth-failure 403 responses now carry ACAO; the /files OPTIONS short-circuit moved ahead of rewrite...break (otherwise skipped, falling through to a 403 auth challenge)
- iOS native Save button failure: WKWebView's Web Share API has incomplete file support — downloads now write chunk-by-chunk to the Cache directory via the Capacitor Filesystem plugin and open the system share sheet via the Share plugin (chunked base64 avoids converting the whole file into one giant string)

Frontend-only release; compose-api is identical to v1.3.0–v1.3.3

## [1.3.3] - 2026-10-03

### Added

- Save button on the download bar once a download completes on iOS: opens the system share sheet with "Save to Files" to pick any location; cancelling keeps the data for another try

### Fixed

- Silent download loss on iOS: iPhone/iPad browsers and PWAs have no web download manager, so completed downloads previously vanished; since the share sheet requires a user gesture per platform rules, the flow is now "download → tap Save"
- Deploy script: IFS newline + eval could join multiple arguments into separate commands, running ssh as a bare interactive shell that hijacked piped stdin (observed executing a confirmation "yes" as a remote command); ssh now always uses -n and retry no longer uses eval

Frontend-only release; compose-api is identical to v1.3.0–v1.3.2

## [1.3.2] - 2026-09-30

### Added

- Settings → About now shows build introspection: the running bundle hash vs. the server's latest ("check for update" with one-tap reload when they differ — a stale-shell PWA can now self-diagnose and self-heal), plus per-page seed status lines for cold-start diagnostics

### Fixed

- Containers/VM tab slow again on cold start: the mount seed source (GraphQL namespace cache) gets deleted by design on mutations, stale poll ticks, and server switches — seeding then fell through to the multi-second live query. The seed now lives in a dedicated storage that is only overwritten by successful refreshes, never invalidated, and is scoped per server URL
- Shares root list slow on cold start for the same reason — same dedicated-seed fix (stale-while-revalidate; disk-wake semantics unchanged, no extra requests)

Frontend-only release; compose-api is identical to v1.3.0/v1.3.1

## [1.3.1] - 2026-09-28

### Added

- File download progress bar: live received/total/percentage with a cancel button; failed or cancelled downloads support resuming from the breakpoint (HTTP Range + If-Range validation — if the file changed in between, it automatically restarts from scratch instead of producing a corrupt file)

### Fixed

- Shares large-file downloads stalling mid-way: the hard 120s timeout is replaced by a 30s stall watchdog (no total time limit) with resumable downloads (Range / If-Range), so big files survive flaky connections
- Containers/VM tab took seconds to open: the page now seeds from the GraphQL cache on mount (stale-while-revalidate) and refreshes silently in the background

Frontend-only release; compose-api is identical to v1.3.0

## [1.3.0] - 2026-09-26

### Added

- Full server backup: export/restore `app-backup.json` (all settings and credentials) in one click — after reinstalling the PWA or switching devices, restore everything with just the WebDAV password
- Network IO card gains receive/transmit rate trend charts and text summaries (primary-interface scope, consistent with the big numbers)

### Changed

- Dashboard trend display rework: CPU / memory / network now show a compact text summary by default ("last N min sampled · avg / peak / low"), with the trend chart collapsed behind a toggle; the expanded chart is upgraded to a detailed mode — auto-scaled axis (snapped to multiples of 5, clamped to 0-100 for percentages), gridlines, time ruler, press-and-drag / hover to inspect any point, and Bézier-smoothed curves
- Destructive compose operations now confirm through a single entry point (the confirmation bar is the only execution path)
- Settings sections reordered: high-frequency items first
- Full-disk file index: the toggle now only controls the daily auto build and defaults to off on install (manual rebuild remains available anytime) — **compose-api updated; re-run the install script on the host to upgrade the backend**
- Deployment script cleanup: removed the broken `deploy-dev.sh` and `deploy-stable.sh --mode dev` image flows (compose runs a different tag, so they never took effect); `build.sh` no longer builds the image/tar that nothing consumed

### Fixed

- Disk temperature history row: no longer shows a meaningless "42–42°C" range when the temperature is flat — a single value is shown instead
- Stale badges ("·3h ago") no longer wrap onto two lines on narrow screens
- `deploy-stable.sh --dry-run` actually performed a real deployment — it now only prints

## [1.2.9] - 2026-09-19

### Added

- Webhook notifications go far beyond container events: new Feishu (Lark) group-bot provider with optional HMAC-SHA256 sign secret, plus 11 new event sources with per-source toggles in Settings — VM stop, UPS power/battery-low, parity check start/finish, disk status, disk temperature, array start/stop, unRAID native alert forwarding (same source as the webGui bell, incl. SMART), log keywords, CPU usage, memory usage and CPU temperature. Threshold-based events come with editable thresholds, hysteresis and cooldown; message templates gain a `{{category}}` variable

### Changed

- Native unRAID alert forwarding now dedupes by alert title: repeats of the same title (disk-temperature flapping, daily Fix Common Problems scans, …) are suppressed inside a 6-hour window (adjustable in Settings) instead of notifying every occurrence

### Fixed

- iOS home-screen PWA: the top bar (logo / bell / badges) rendered blurry while Safari was sharp. Root cause: `apple-mobile-web-app-status-bar-style=black-translucent` extends the PWA webview under the iOS status bar, where iOS applies a system-level blur — and iOS snapshots this setting into the home-screen icon, so no app update alone could fix it. The meta is now `black`. **Existing users must delete the home-screen icon and re-add it from Safari (Share → Add to Home Screen) to pick up the fix**

## [1.2.8] - 2026-09-19

### Changed

- Global search is now mobile-first: the file-search action sits directly under the input (was at the very bottom of the results), the input uses `enterKeyHint="search"` so phone keyboards show a "Search" key, and pressing Enter with a query (without arrow-key selection) runs the file search straight away — Pro searches the index, free tier enters the spin-up confirmation. Arrow-down then Enter still opens the highlighted result
- The real-time full-disk search links now spell out what happens ("nothing found? search all disks (wakes sleeping drives, ~1-2 min)")

### Fixed

- Tapping the search button on mobile now pops the keyboard immediately — the input is focused synchronously inside the tap gesture (was focused in a rAF callback, which mobile browsers block, requiring a second tap)
- PC keyboard hints no longer show on mobile: global search / command palette footers and the text editor's "Ctrl+S · Esc" status line are desktop-only now; command palette placeholder no longer shows "Ctrl+Shift+P" on phones

## [1.2.7] - 2026-09-19

### Added

- Full-disk file index (Pro): the host builds a filename index of `/mnt/user` once a day (cron, default 03:00) into the cache pool; searching files in global search reads the index — millisecond results with zero disk spin-up. New "Full-disk file index" card in Settings: on/off toggle, daily build hour picker (on the hour, 0-23), index stats (entries / size / built-at) and a manual rebuild button. Requires updating compose-api on the host (install script ships `update-file-index.sh` + `register-index-cron.sh`; old backends show an upgrade hint)

### Changed

- Global search file search is now a single entry: Pro users search the index directly (no spin-up); free users keep the v1.2.6 real-time full-disk search (with the spin-up confirmation) and see an unlock hint. The "cache pool only" search tier is removed from the UI (backend `scope=cache` kept for v1.2.6 compatibility)
- Full-disk file index is a Pro feature (Settings card and index search are gated)

### Fixed

- Cache-only shares that appear as symlinks under `/mnt/user` (e.g. `strm`, `appdata`, `isos`) were silently skipped by both the index build and the real-time full-disk search — `find` does not follow symlinks by default. Both now follow top-level share symlinks explicitly (index grew from 1.35M to 1.59M entries on the reference server)

## [1.2.6] - 2026-09-18

### Added

- Full-disk file search: a new "search everywhere" action in global search (below the cache-pool action) scans `/mnt/user` (the FUSE union of cache + all array disks) via compose-api `?action=search&scope=all`. It runs only after an explicit in-place confirmation warning that sleeping disks will be spun up (may take 1-2 minutes); the confirmation resets whenever the keyword changes, there is no "remember my choice", and the backend writes an audit log line for every full search. Requires updating compose-api on the host (old backends show an upgrade hint)
- Cache-pool search with zero hits now points to the full-disk search action

## [1.2.5] - 2026-09-18

### Added

- Global search (Cmd+K) now covers VMs, root shares and Compose stacks (read from existing caches only — never triggers new requests), with grouped results, recent-search history, and fuzzy matching with English/pinyin aliases (e.g. `docker` finds 容器/VM, `rz` finds 日志)
- Cache-pool file search: an explicit "search files on cache pool" action in global search calls the new compose-api `?action=search` endpoint (host-side `find` over `/mnt/cache` only — array disks are never touched and sleeping disks are never woken). Results deep-link into the Shares page. Requires updating compose-api on the host (install script updated; old backends show an upgrade hint)
- Containers page: in-page search box filtering Docker containers (name/image/state), VMs and Compose stacks with one input
- iOS app shell via Capacitor 8 (`ios/` in-repo; native mode prefixes all API paths with the active server URL, web behavior unchanged). Simulator smoke passed; device signing is a manual user step

## [1.2.4] - 2026-08-23

### Fixed

- Update badges for Docker containers and Compose stacks: new compose-api `?action=updates` endpoint is now the authoritative update source, working around three upstream unraid-api issues (daily digest cron disabled upstream, GraphQL not normalizing the `library/` prefix for official images, cached local digest frozen after out-of-band pulls) — badges for official images (e.g. ms-go) now show, and clear right after pull/rebuild
- Fix false-positive update badges caused by multi-RepoDigest rows in `docker images --digests` (now per-ref `docker image inspect`)
- Fix stacks with large command logs (e.g. Teslamate, >64 KB) failing with "HTTP 200" when opening details or pulling images: byte-truncated log tails could split multi-byte UTF-8 characters, breaking JSON encoding and returning an empty body
- Fix the stack detail modal log section collapsing while pull/rebuild is running

## [1.2.3] - 2026-08-21

### Security

- File access endpoints `/files` and `/dav/` now send `Content-Security-Policy: sandbox`: HTML/SVG files uploaded via WebDAV can no longer execute scripts in the app's origin when opened, so they cannot read the API key stored in `localStorage` (which grants full GraphQL and compose-api access). Image/text/download previews are unaffected
- Added `X-Content-Type-Options: nosniff` to the `/files`, `/dav/`, and `/var/log/` locations (they define their own `add_header` directives, which previously dropped the server-level `nosniff` inheritance)

## [1.2.2] - 2026-08-14

### Changed

- Dashboard card sorting: the move up/down buttons are folded into a popup menu on the drag handle. Only the handle is visible by default (100px → 36px), so it no longer overlaps card titles; keyboard arrows and 32px touch targets are preserved
- Dashboard drag preview: while dragging a card, the cursor-following image is now a full-size translucent clone of the card (rounded corners + shadow) instead of the browser's default translucent "white box" snapshot of the tiny handle

### Fixed

- Sort controls no longer leave a translucent background stuck after tapping on iOS (sticky `:hover`); hover backgrounds were removed in favor of active-state feedback + keyboard focus ring
- Alert popup "Open in WebUI" opened a blank page in iOS PWA (JS `window.open` is unreliable in PWAs, and deep links require a webGui session) — all WebUI entries now point to `{serverUrl}/login` via a real `<a target="_blank">` link
- README: screenshot placeholders replaced with actual app screenshots

## [1.2.1] - 2026-08-10

### Fixed

- Shares file manager: `#` filenames were truncated by URL fragment parsing; Chinese rename/move/copy failed (MOVE/COPY `Destination` header threw on non-ASCII) — DAV paths are now encoded uniformly at every exit point
- Shares root manual refresh was a no-op within the 30-minute cache window (cache namespace is now invalidated first)
- Shares large-file download/preview was killed by the 15s default DAV timeout (raised to 120s for full-file reads)
- Shares share links were double-encoded, causing 404 for Chinese paths
- Safari could produce NaN dates in file listings (autoindex date parsing no longer relies on `Date.parse` locale behavior)
- Settings: server URLs with spaces after the protocol, missing protocol, or invalid format were saved as-is and broke the app — all save paths now normalize and validate the URL
- Settings "About" version was hardcoded and stale; it is now injected at build time from `package.json`
- `release.sh` now syncs the `package.json` version field during releases

### Known limitations

- Multi-server switching only swaps the API key; data requests still go through this container's same-origin proxy (the unRAID host running the container). Single-server usage is unaffected.

## [1.2.0] - 2026-08-09

### Added

- Enhanced VM details (CPU/memory/disks/network/passthrough/snapshots, read from libvirt XML)
- Local alert list in the Dashboard alert bell (unRAID notifications viewable in-app)

### Fixed

- PWA caching overhaul: build-hash-versioned service worker, no-cache headers, auto-reload on update — iOS PWAs no longer stick on old bundles
- Dashboard fixes: manual refresh cache invalidation, trend chart labels, weighted array usage, favorites touch targets, retry button on error banner
- Container tab fixes: refresh button disabled state, unified cache invalidation, VM deep-link highlight
