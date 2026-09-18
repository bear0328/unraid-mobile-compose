# Changelog

All notable changes to this project are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/).

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
