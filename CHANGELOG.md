# Changelog

All notable changes to this project are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/).

## [TestFlight 2.0.0 (160)] - 2026-11-28

### Fixed

- Configuration backups now include Emby/Jellyfin servers, user selections, per-directory sorting, and per-server API keys instead of only polling and appearance preferences
- Added dashboard layout, share/file sorting, and App Lock settings. Future UserDefaults configuration and Keychain credentials are included by default without another allowlist entry
- Configuration changes automatically trigger a debounced upload, and restores immediately refresh media configuration and player state
- Search history, playback progress, and the License binding cache remain excluded. Backups contain secrets and use WebDAV authentication, not encryption

### Notes

- Native app only; Web only adds compatible backup-field types with no runtime behavior change. Web, Docker, and compose-api remain at v1.5.15, with no server deployment or install-script rerun needed
- Full native XCTest 973/973 and Vitest 1954/1954 pass, together with lint and a production build. Real-device full backup and restore remain subject to user acceptance

## [TestFlight 2.0.0 (159)] - 2026-11-28

### Changed

- Removed the duplicate Features & Services WebGUI-address editor. The address is now maintained only under Current Server → Edit Server
- Editing or clearing only the WebGUI address no longer requires another App/API connection test. Server URL or API Key changes still require verification
- WebGUI-only saves preserve the existing no-connection-rebuild update path, avoiding unnecessary data-request refreshes

### Notes

- Native app only; Web, Docker, and compose-api remain at v1.5.15, with no server deployment or install-script rerun needed
- Full native XCTest 966/966 and Vitest 1954/1954 pass, together with lint and an isolated-directory production build. Real-device settings editing remains subject to user acceptance

## [1.5.15] - 2026-11-28

### Fixed

- Rerunning the installer with an existing `apikey` now preserves it by default instead of prompting or overwriting it. First-time installation is unchanged, and only an explicit `--reset-key` rotates the key, preventing upgrades from breaking host authorization on Web or native devices

### Changed

- Native host-agent 401/403 responses now show API Key Mismatch with a direct Edit Server action instead of appearing unavailable or upgradable
- Connection checks separate GraphQL and host-agent authorization. A missing or unavailable host agent no longer blocks saving for free features

### Notes

- Coordinated with TestFlight 2.0.0 (158). compose-api only updates the installer's key-preservation policy and version number; v1.5.14 hosts can synchronize in place
- Full native XCTest 965/965 and Vitest 1954/1954 pass, together with lint, production build, and release-manifest verification. Real-device authorization recovery remains subject to user acceptance

## [TestFlight 2.0.0 (158)] - 2026-11-28

### Changed

- Host authentication failures now show API Key Mismatch and link directly to Edit Server for key recovery
- Connection checks distinguish GraphQL from host-agent authorization, so a successful GraphQL check no longer implies host-agent acceptance

### Notes

- Coordinated with Web/Docker and compose-api v1.5.15. All 965 native XCTest cases pass; real-device authorization recovery remains subject to user acceptance

## [1.5.14] - 2026-11-28

### Changed

- Simplified manual upgrades across the native app and Web: Docker updates point to the native container detail, while legacy host agents offer a single copyable pinned-version upgrade command
- The copied command uses a fresh temporary directory instead of reusing an old installer's `api.php`, preserves the existing API key, and enables in-app synchronization for later releases

### Notes

- Coordinated with TestFlight 2.0.0 (157). compose-api only advances `APP_VERSION` to 1.5.14 and adds no host functionality; v1.5.13 hosts can synchronize in place
- Full native XCTest 961/961 and Vitest 1952/1952 pass, together with lint and production build checks. Real-device copy and terminal execution remain subject to user acceptance

## [TestFlight 2.0.0 (157)] - 2026-11-28

### Changed

- Replaced the legacy host-agent instructions with one Copy Upgrade Command button, using a fresh temporary directory and pinned installer while preserving the existing API key
- Docker updates now point directly to the native container detail instead of showing multiple Compose or verification commands

### Notes

- Coordinated with Web/Docker and compose-api v1.5.14. All 961 native XCTest cases pass; real-device copy and terminal execution remain subject to user acceptance

## [TestFlight 2.0.0 (155)] - 2026-11-28

### Changed

- Renamed the Shares tab and page to Storage, clarified the Shares / Disks categories, and aligned global-search wording
- Reworked share and disk rows with names, comments, used / total / free capacity, and usage bars. Added first-load skeletons, non-blocking stale-data warnings with retry and last-updated time, and partial pool-failure status
- Added a dedicated share model that preserves comments and capacity fields while decoding legacy snapshots. Shares and pools now load concurrently without introducing polling

### Notes

- Native app only; Web, Docker, and compose-api remain at v1.5.13, with no install-script rerun needed
- All 952 native XCTest cases and the generic iOS unsigned build pass. Light, dark large-text, and small-screen layouts were checked; real-device online refresh and interactions still require validation

## [TestFlight 2.0.0 (154)] - 2026-11-28

### Changed

- Library cards now use 16:9 landscape artwork, showing banners without cropping and fixing image overflow and card overlap

### Notes

- Native app only; Web, Docker, and compose-api remain at v1.5.13. All 948 native XCTest cases pass

## [1.5.13] - 2026-11-27

### Changed

- Unified the confirmed orange full-bleed brand artwork across the native app icon, Web favicon and PWA icons, Capacitor icon and splash screens, and the public deployment repository. Native icons retain the standard full-bleed source so iOS applies its continuous rounded mask
- Continue Watching now shows one card per series, using the series name and poster with the latest resumable episode as the subtitle. Tapping still resumes that episode directly; movies remain individual entries

### Notes

- Coordinated with TestFlight 2.0.0 (149). compose-api only advances `APP_VERSION` to 1.5.13 and adds no host functionality; v1.5.12 hosts can synchronize in place without manually rerunning the install script
- Full native XCTest 948/948 and Vitest 1951/1951 pass, together with lint and production build checks. Real-device Continue Watching and icon appearance remain subject to user acceptance

## [TestFlight 2.0.0 (149)] - 2026-11-27

### Changed

- Continue Watching collapses multiple episodes from the same series into the most recently played entry. Cards show the series title and poster, retain the episode number and title as secondary text, and continue to resume playback directly
- Updated the app icon to the confirmed orange design with no white outer corners; iOS applies the native continuous rounded mask

### Notes

- Coordinated with Web/Docker and compose-api v1.5.13. All 948 native XCTest cases pass; real-device series grouping, direct resume, and icon rendering still require validation

## [1.5.12] - 2026-11-27

### Changed

- Reorganized native Settings into seven groups with a unified Pro entry, consistent credential save/validate/clear flows, and a dedicated host-agent subpage with backend status. Polling and appearance preferences are now included in automatic backups
- Configuration restore now performs a complete preflight and transactional commit/rollback, rejects invalid versions, IDs, and URLs, and uses safe cache paths

### Security

- Connection tests are bound to the validated snapshot. Keychain writes and deletions are verified by reading them back, with recovery on failure
- Nginx `/config/` now makes only GET/OPTIONS requests to the exact `settings.json` path public. All other configuration, including License device/activation records and `app-backup`, requires DAV authentication by default. Backups contain secrets and are protected by WebDAV authentication, not encryption

### Notes

- Coordinated with TestFlight 2.0.0 (145). compose-api only advances `APP_VERSION` to 1.5.12 and adds no host functionality. Hosts on v1.5.11 can synchronize in place without manually rerunning the install script
- Full native XCTest 943/943, the latest targeted suite 59/59, and Vitest 1951/1951 pass. Simulator checks cover light/dark appearance and large text; real-device interactions remain subject to user acceptance

## [TestFlight 2.0.0 (145)] - 2026-11-27

### Changed

- Reorganized Settings into seven groups, unified the Pro entry and credential save/validate/clear behavior, and moved host-agent controls and status into a dedicated subpage
- Added polling and appearance preferences to automatic backups

### Security

- Bound connection tests to validated snapshots, with Keychain write/delete read-back verification and failure recovery
- Added complete configuration-restore preflight, transactional commit/rollback, rejection of invalid versions/IDs/URLs, and safe cache-path handling

### Notes

- Coordinated with Web/Docker and compose-api v1.5.12. compose-api only advances `APP_VERSION` and adds no host functionality; v1.5.11 hosts can synchronize in place without manually rerunning the install script
- Full native XCTest 943/943, the latest targeted suite 59/59, and Vitest 1951/1951 pass. Simulator checks cover light/dark appearance and large text; real-device interactions remain subject to user acceptance

## [TestFlight 2.0.0 (144)] - 2026-11-27

### Changed

- Newly Added now collapses episodes with the same `SeriesId` into one series card instead of listing every episode. Cards use the series name and poster, open the series for season/episode selection, and leave movies as individual entries
- Items are sorted by `DateCreated` before series deduplication, so each show's position reflects its newest added episode. Orphan episodes without series identity no longer occupy standalone cards

### Notes

- Native app only; Web, Docker, and compose-api remain at v1.5.11, with no install-script rerun needed
- All 924 native XCTest cases pass. Real-device series grouping, ordering, and navigation against actual Emby/Jellyfin servers still require validation

## [TestFlight 2.0.0 (143)] - 2026-11-27

### Fixed

- Newly Added episodes now prefer series posters and fall back to season posters instead of prioritizing episode video-frame thumbnails. Movies and series continue to use their own artwork
- Artwork cache identities use the selected series or season item and tag. Continue Watching retains its independent season-first, series-fallback behavior

### Notes

- Native app only; Web, Docker, and compose-api remain at v1.5.11, with no install-script rerun needed
- All 923 native XCTest cases pass. Real-device Newly Added artwork and fallback ordering against actual Emby/Jellyfin servers still require validation

## [TestFlight 2.0.0 (142)] - 2026-11-27

### Changed

- Reorganized the native player home into Libraries, Continue Watching, and Newly Added, each horizontally scrollable. Libraries now contain entry cards only rather than previews of every library's contents
- Newly Added merges items across libraries by `DateCreated`, sorts newest first, deduplicates them, and shows up to 20 entries in one row instead of separate per-library rows
- Continue Watching retains its progress bar while episodes prefer season posters and then series posters over episode video frames. Artwork cache identities use the selected artwork item and tag

### Notes

- Native app only; Web, Docker, and compose-api remain at v1.5.11, with no install-script rerun needed
- All 921 native XCTest cases pass. Real-device layout, cross-library ordering, and season/series poster fallback against actual Emby/Jellyfin servers still require validation

## [1.5.11] - 2026-11-27

### Added

- Native App and Web Settings can now synchronize the Pro host agent. They download only a pinned official release, verify every file with SHA-256, install through an independent background worker, preserve the API-key hash and License receipt, and provide logs plus request-ID recovery after disconnects
- Host upgrades add pre-install backups, version/live-file verification, rollback on failure, downgrade prevention, and release manifests covering both the installer and every payload file

### Fixed

- Unexpected Web activation errors now roll back and always clear the busy state. Host-agent authorization failures show a visible warning and a link to host synchronization instead of being silently ignored
- Settings now labels `capabilities.appVersion` as the host-agent version rather than implying it is the running Docker image version

### Notes

- Coordinated with TestFlight 2.0.0 (141). Initial installation still requires root in the Unraid web terminal or SSH. A v1.5.10 or older host agent must be manually updated to v1.5.11 one final time; later releases can synchronize in place

## [TestFlight 2.0.0 (141)] - 2026-11-27

### Added

- Native Settings can synchronize the Pro host agent from a pinned official release, with full-package SHA-256 verification, preserved API-key/License credentials, progress logs, request-ID recovery, and clear failure or indeterminate-outcome states

### Changed

- Coordinated with Web/Docker and compose-api v1.5.11. Settings now identifies `capabilities.appVersion` as the host-agent version, not the running Docker image version

### Notes

- Initial installation still requires root in the Unraid web terminal or SSH. A v1.5.10 or older host agent must be manually updated to v1.5.11 one final time before later releases can synchronize in place

## [TestFlight 2.0.0 (140)] - 2026-11-27

### Added

- Added background audio with an independent audio session, interruption resume rules, headphone-unplug pause, route-change handling, and safe recovery after audio-service resets. The app declares only the `audio` background mode
- Added music playback queues for WebDAV audio in the current folder and currently loaded Emby/Jellyfin audio, with sequential/shuffle playback, list/single-track repeat, previous/next, automatic advance, seek, resume, and lock-screen queue commands
- Added audio-specific Now Playing metadata and artwork, plus Emby/Jellyfin Started, Progress, and Stopped reporting for music

### Changed

- Kept video playback on the existing player and original-quality Direct Play path. No automatic remux, transcode, bitrate reduction, or subtitle burning is introduced
- Music queues contain only currently loaded audio; directory refreshes cannot replace an active queue, and changing server credentials closes the music player

### Fixed

- Saving progress and stopping the old reporter now happen before changing tracks. Progress cannot be saved or reported against a new track before its stream is bound
- Decoder failures are visible and retryable in the music player, abnormal endings do not trigger automatic advance, and audio with initially unknown duration can resume after its real duration becomes available

### Notes

- Native app only; Web, Docker, and compose-api remain at v1.5.10, with no install-script rerun needed
- PiP is not included. Real-device background audio, interruption, Bluetooth/route, lock-screen, and continuous music playback validation remains required

## [TestFlight 2.0.0 (139)] - 2026-11-27

### Added

- Rebuilt the native Emby/Jellyfin media center with Continue Watching, latest items per library, library cards, list/poster layouts, resilient artwork loading, and episode-to-series poster fallback
- Added lock-screen and Control Center metadata and artwork, explicit play/pause, 15-second skip controls, and seek commands for media that reports seek support. Player lock also blocks system remote commands

### Changed

- Directory snapshots now restore instantly and refresh online while remaining isolated by server, user, directory, and sorting. Cached entries exclude media paths and remote STRM sources; playback still requires a fresh authorized source
- Direct Play keeps the URL, container, credentials, media-source ID, and reporting session bound to one selected source. Users can retry or manually choose another original-quality source with a fresh session and video canvas; no automatic remux, transcode, bitrate reduction, or subtitle burning is introduced

### Fixed

- Remote playback failures now distinguish authentication, missing sources, HTTP errors, empty responses, and JSON/XML/HTML error documents, including responses with generic or missing MIME types
- Prevented stale home loads, directory pages, artwork requests, and remote commands from affecting a newer server, sort order, playback source, or dismissed player

### Notes

- Native app only; Web, Docker, and compose-api remain at v1.5.10, with no install-script rerun needed
- Background audio, Picture in Picture, and music queues are not included. Real-device lock-screen, Bluetooth, media-source, and weak-network playback validation remains required

## [1.5.10] - 2026-11-27

### Fixed

- Docker “installed” states in the app store now use only actual running or stopped containers, matched precisely by container name or image repository. Leftover user templates and community templates no longer count as installed; plugin `.plg` detection is unchanged

### Changed

- Coordinated with TestFlight 2.0.0 (138). **Re-run the host install script after upgrading** to deploy the compose-api installation-state fix

## [TestFlight 2.0.0 (138)] - 2026-11-27

### Fixed

- Emby/Jellyfin Started reports now retry within a fixed limit. Progress retains only the latest pending position on weak networks, while closing or retrying playback terminates the old session and prioritizes Stopped without queued progress or duplicate stops
- Remote media Range probes read at most one byte instead of aggregating a large response when a server ignores Range. Cross-origin redirects no longer forward media-server or WebDAV credentials, and playback reports expose the player's real seek capability

### Changed

- Removed the duplicate App Store entry from the container overview; the Settings entry and its free/Pro routing remain unchanged
- Coordinated with Web/Docker and compose-api v1.5.10. **Re-run the host install script after upgrading**

## [1.5.9] - 2026-11-23

### Added

- Native one-click updates now cover every updatable Docker container: standalone containers, compose.manager stacks, and external Compose projects such as `videodock`. External projects are discovered from live Docker labels and updated once per project with their original project name, working directory, and ordered Compose files
- Update confirmation separately lists standalone containers, compose.manager projects, and external Compose projects with their pending members. Compose projects clearly warn that other services in the same project may also restart
- External Compose updates add project-level asynchronous jobs, progress logs, request-ID recovery, active-job attachment, and immediate update-badge refresh using the complete Compose configuration

### Security

- External Compose updates never fall back to standalone container updates. The server derives project paths from live labels, validates the expected container ID and Compose specification before and after pulling, and rejects client-supplied paths or commands
- Project locks remain held while queued workers cannot be proven to have exited. Discovery failures stop update preparation instead of misclassifying Compose containers as standalone, and indeterminate outcomes remain `unknown`

### Changed

- Coordinated with TestFlight 2.0.0 (137). **Re-run the host install script after upgrading** to deploy the new external Compose API and update-status support

## [TestFlight 2.0.0 (137)] - 2026-11-23

### Added

- “Update All” and selected-container updates now include standalone Docker, compose.manager, and external Compose containers, with a categorized pre-update list and one execution per Compose project
- Container details update the owning external Compose project instead of offering an invalid standalone update. Progress, logs, success/failure/pending summaries, and job reattachment use the same project classification

### Fixed

- Active external Compose jobs can be reattached by project even while their anchor container is being recreated; jobs that finish during reattachment return their real terminal result
- Coordinated with Web/Docker and compose-api v1.5.9. **Re-run the host install script after upgrading**

## [1.5.8] - 2026-11-23

### Fixed

- Support Unraid 7.4 renaming `flashGuid` to `flashGUID` while retaining compatibility with the legacy field. Pro re-authorization no longer falsely reports that the local Unraid identity is unreadable, which previously blocked “Update All” and other Pro actions before execution
- Device binding still uses the SHA-256 hash of the same GUID, with unchanged license verification and offline rules. This is a compose-api backend fix with no native app feature changes; **re-run the host install script after upgrading**

## [TestFlight 2.0.0 (136)] - 2026-11-23

### Changed

- Dashboard CPU, GPU, memory, network, disk, and Parity cards can collapse into single-line summaries while retaining their key values. Tap the title row or use the long-press menu to toggle a card
- Collapsed states persist independently for each card. All cards remain expanded by default, while “Reset Layout” restores both the default order and expanded states; existing detail expansion states are preserved
- Collapsing is visual only: polling is unchanged, disk details are not fetched, and sleeping disks are never spun up. VoiceOver hints and Reduce Motion are supported
- Dashboard cards are now organized into independent source files with shared trend and detail components, preserving the existing interface and behavior
- Native app only; Web, Docker, and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (135)] - 2026-11-23

### Changed

- The dashboard now supports pull-to-refresh through the same data path as toolbar refresh and error retry. Regular refreshes do not fetch disk details or spin up sleeping disks
- The disk card now explains its unloaded state and provides a “Fetch Disk Details” button instead of a blank placeholder; spinning up disks still requires separate confirmation
- Coordinated with Web/Docker v1.5.7. **Re-run the host install script after upgrading** to enable the GPU sampling optimization

## [1.5.7] - 2026-11-23

### Changed

- GPU metrics now use a shared four-second cache, preventing Intel/NVIDIA sampling commands from starting on every one-second live poll. Intel model detection is cached per process, while CPU power and memory remain live
- The Web container summary removes the duplicated running count from its title; the ring and legend continue to show utilization and per-state totals
- Coordinated with TestFlight 2.0.0 (135). **Re-run the host install script after upgrading** to deploy the updated compose-api

## [TestFlight 2.0.0 (134)] - 2026-11-23

### Fixed

- Container updates, lifecycle actions, parameter editing, and batch jobs now exclude each other at the final execution layer. Each batch retains its original server client and configuration generation, stopping remaining mutations and refreshes after a configuration change
- Compose and standalone containers publish update results incrementally. Lost responses, timeouts, and undispatched items remain neutral “outcome pending” states instead of false failures or conflicts
- Compose detail, log, and YAML-save requests consistently escape special stack names. VM state matching trims and lowercases input, while log sessions rebuild after server configuration changes

### Changed

- Containers, Compose, and VMs share neutral cards, 42pt icons, tappable summary filters, and resilient long-text layouts. Non-editing rows support VoiceOver activation, while summary cards and badges reflow for large text
- Native app only; Web, Docker, and compose-api are unchanged, with no install-script rerun needed

## [1.5.6] - 2026-11-23

### Security

- Standalone Docker updates now carry `expectedId/requestId` and persistent job state. Identical requests recover the original job, while the server rechecks container identity, Compose ownership, runtime state, and template revision before and after pulling
- Update workers run in a separate process group with atomic status and result writes. Orphaned locks are reclaimed only after the entire group exits, and an indeterminate terminal state is reported as `unknown` rather than false success or failure

### Fixed

- After timeouts, network failures, 5xx responses, or cancellation, the native app recovers with the original `requestId` instead of replaying PUT. Legacy-backend fallback, active-job conflicts, and identity/Compose conflicts are now strictly distinguished
- Single and batch updates expose a neutral “outcome pending” state, so lost responses and final polling timeouts are not counted as successful or definitively failed updates

### Changed

- Existing clients, workers, and the original `log/running/result` response remain compatible; container parameter editing shares the same orphaned-job recovery
- Coordinated with TestFlight 2.0.0 (133). **Re-run the host install script after upgrading** to deploy the updated compose-api

## [TestFlight 2.0.0 (133)] - 2026-11-23

### Fixed

- Container details, multi-selection, and one-click updates now share Compose ownership decisions, preventing Compose-managed containers from entering the standalone Docker update path
- Container logs now handle unavailable cursors, foreground/background lifecycle changes, and a 3,000-line retention limit. Lost update responses recover through the original request ID without repeating the update

### Changed

- The base container list appears first while update checks and icons load in parallel. State filtering now intersects with search, and rows use stable name/ID ordering
- The multi-selection bar shows the visible selected count with large-text-friendly actions, the overview adds a Community Applications shortcut, and Compose details explain parameter-editing limitations
- Update outcomes include a neutral pending state, with unknown results counted separately in batch summaries
- Coordinated with Web/Docker v1.5.6 and the updated compose-api. **Re-run the host install script after upgrading**

## [1.5.5] - 2026-11-23

### Changed

- Web container selection is now entered explicitly, with consistent loading skeletons, filtered empty states, cached-data errors, and long-text layouts across containers, Compose, and VMs; the batch toolbar remains safe-area aware
- Update tasks distinguish complete success, partial completion, and failure, collapse lengthy error details by default, and report batch execution versus image updates accurately
- Coordinated with TestFlight 2.0.0 (132). **Re-run the host install script after upgrading** to align compose-api release metadata

## [TestFlight 2.0.0 (132)] - 2026-11-23

### Fixed

- Container and VM batch controls now use the system safe area instead of overlapping the floating iOS 26 tab bar; filtered empty states are no longer obscured by the tab bar
- Refresh errors and cached-data context remain visible when filtering returns no rows, and batch actions target only visible rows that remain selected

### Changed

- The existing container/Compose/VM selection menu is retained, with improved narrow-screen layouts for long Compose names, mixed states, failure records, and VM UUIDs
- Update tasks distinguish complete success, partial completion, and total failure, collapse lengthy error details, and honor Reduce Motion for explicit animations
- Coordinated with Web/Docker v1.5.5. **Re-run the host install script after upgrading** to align compose-api release metadata

## [1.5.4] - 2026-11-23

### Fixed

- Container batch actions now exclude selections hidden by filtering. Docker auto-start and creation fields survive list/detail mapping, stats recover after failed polls, and concurrent row actions retain independent loading states
- Live container logs de-duplicate in-flight polls and ignore responses from closed or replaced sessions, preventing stale log updates

### Changed

- Containers gain URL-synchronized keyboard-accessible tabs, separate Docker/VM freshness, search empty states, Compose loading skeletons, a safe-area-aware sticky batch toolbar, and larger touch targets
- Image update checks and icon loading run in parallel, while memoized log lines avoid repeatedly parsing unchanged ANSI content. Selection, tabs, batch actions, and update tasks are separated into focused components and hooks
- Web/Docker UI release; native app features are unchanged. **Re-run the host install script after upgrading** to align compose-api release metadata

## [TestFlight 2.0.0 (128)] - 2026-11-22

### Added

- Settings → About now distinguishes **unRAID-Mobile App** from **unRAID-Mobile Docker**, showing the installed App and host-agent/Docker versions separately
- An older Docker/backend release shows an update-available notice with manual upgrade steps. App updates do not update Docker automatically; updating the container and re-running the host install script are both required
- Legacy backends without a version field are identified as upgradeable, while network failures do not create false update notices and newer backends never prompt a downgrade

### Changed

- Coordinated with Web/Docker v1.5.3 and the updated compose-api. **Re-run the host install script after upgrading**

## [1.5.3] - 2026-11-22

### Added

- Web and native About sections now show the App version and the actual Docker/host-agent version independently, with an update-available state when the backend is older than the App's companion release
- Manual guidance covers updating the container, re-running `install-compose-api.sh`, and refreshing to confirm the version. Missing legacy version fields, unavailable network requests, and newer backends are handled distinctly
- compose-api `capabilities` now returns `appVersion`; the release script keeps this value, `package.json`, and the native companion Docker version synchronized before calculating the installer SHA-256

### Changed

- Web, TestFlight 2.0.0 (128), and compose-api are a coordinated release. **Re-run the host install script after upgrading**

## [TestFlight 2.0.0 (127)] - 2026-11-22

### Fixed

- Emby episode resume choices are no longer hidden by a local zero-position entry. Valid local positions retain priority, otherwise the server's PlaybackPositionTicks provides Resume / Start from Beginning
- Playback reporting waits until the resume choice is dismissed, preventing an initial zero position from clearing the server record. Progress is reported during playback, seeking, pausing and closing, and the current media list reloads after playback
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (126)] - 2026-11-22

### Security

- Added local compose-api signature verification and a machine-bound receipt for License authorization. The full key is sent only to the user's own Unraid host agent; the central service still receives only SHA-256 hashes of the key and flash GUID. First activation still requires central online binding, while local host-agent synchronization is best-effort and does not block other Pro features
- Added UI and Store enforcement for direct GraphQL container, VM and parity actions. Confirmation reads the latest authorization state, missing Providers fail closed, and free users no longer periodically request Pro CPU temperature

### Fixed

- Keychain save failures no longer report false activation success. Failed activation preserves the previous License and binding state, and a temporarily unavailable local host agent does not roll back other completed Pro activation
- Docker updates no longer repeat the GraphQL mutation after timeouts, network errors, 403, 502/503 or other ambiguous outcomes. Pro write requests now use a 20-second timeout to cover the host agent's initial central confirmation

### Changed

- Coordinated with Web/Docker v1.5.2 and the new compose-api. **Re-run the host install script after upgrading**. Older clients do not send the License header and receive 403 from Pro host-agent routes

## [1.5.2] - 2026-11-22

### Security

- compose-api now verifies the original License ECDSA signature, expiration and machine-bound receipt on Pro routes. Clients send the full License only to their own host agent; the central service continues to store hashes only
- First activation requires central online binding. Local host-agent synchronization is best-effort; establishing a receipt on a new host agent requires network access once, after which the local receipt and original key support offline verification while the signed expiration remains enforced

### Fixed

- Failed Web activation restores the previous License, state and binding cache instead of deleting the old authorization. A missing, legacy or temporarily unavailable host agent does not block other Pro features
- Single Docker updates fall back to GraphQL only when the host agent explicitly lacks the new action. Timeouts, network errors, 403, 502/503 and business errors no longer repeat updates; Pro write timeouts include central-confirmation headroom

### Changed

- Web, TestFlight 2.0.0 (126) and compose-api are a coordinated release. **Re-run the host install script after upgrading**. Older clients receive 403 from Pro host-agent routes because they do not send the License header

## [TestFlight 2.0.0 (125)] - 2026-11-22

### Fixed

- Targeted devices where build 124 still froze on the target frame after MPEG-TS seeks while audio continued. Affected streams can have large PTS/DTS reordering gaps that make VLC repeatedly discard decoded frames as late; the TS avformat software-decode path now allows late video frames instead of dropping them
- Kept the player and canvas restart behavior from build 124 for TS seeks. MP4, MKV and other containers retain their existing output and in-place seek behavior
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (124)] - 2026-11-22

### Fixed

- Addressed devices where build 123 still left MPEG-TS seeks frozen on the target frame while audio continued. The avformat software-decode path now destroys and recreates the entire VLC player and video canvas instead of replacing only the media input; delayed state and timeline callbacks from the old player are isolated
- TS seeks preserve play/pause state, audio/subtitle track selections, external subtitles, playback rate and subtitle delay; MKV, MP4 and other containers keep in-place seeking
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (123)] - 2026-11-22

### Fixed

- Addressed MPEG-TS seeks where audio continued but the picture remained frozen. TS sources using the avformat software-decode path now reopen at the target time to rebuild demuxing, decoding and video output. Play/pause state, audio/subtitle track selections and external subtitles are preserved; MKV, MP4 and other containers keep in-place seeking
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (122)] - 2026-11-22

### Fixed

- Addressed remote Emby/STRM MPEG-TS playback on some devices where the timeline advanced after build 120 but the picture remained frozen. TS sources using the FFmpeg demuxer now use software decoding to avoid the VideoToolbox output compatibility issue; MKV, MP4 and other containers keep their existing decode paths
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (121)] - 2026-11-22

### Added

- Added Jellyfin alongside Emby in the native player. Settings → Media Servers selects the service type, while the existing Player tab provides libraries, artwork, user switching, original-stream Direct Play, resume positions and playback progress reports
- Jellyfin preserves reverse-proxy subpaths and uses the server root rather than appending Emby's API prefix. Existing Emby configurations, keychain credentials, server-specific preferences and local resume entries remain compatible

### Security

- Jellyfin API, artwork, playback and subsequent Range/seek requests use `Authorization: MediaBrowser Token="..."` instead of unsupported query or legacy header authentication. Remote STRM sources never receive the media server's authorization header

### Changed

- Server-side transcoding is still not included. Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (120)] - 2026-11-22

### Fixed

- Remote Emby/STRM MPEG-TS videos no longer stop after the first frame or report an early end on affected H.264 streams. Recognized TS sources use VLCKit's FFmpeg demuxer, including extensionless CDN URLs with generic MIME types, without transcoding or changing MKV/MP4 playback
- Playback that ends far before its known duration now surfaces a retryable error instead of silently appearing to complete

### Changed

- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (119)] - 2026-11-21

### Fixed

- Sort field and direction now persist independently per Emby server, user and directory. Libraries, series and seasons no longer overwrite one another, and each directory restores its own choice after navigation or app relaunch
- Series and season directories now default to ascending `IndexNumber`, preventing title sorting from scrambling episode order. The menu labels these options as season or episode number, while name, date added, premiere date and rating remain available per directory
- The global preference from build 118 is used only as a compatibility fallback for library roots, not for series or season directories

### Changed

- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (118)] - 2026-11-21

### Fixed

- Episode lists and playback titles now display season and episode numbers from `ParentIndexNumber / IndexNumber`, such as `S01E03 · Episode Name`; specials support `S00`, while missing numbers fall back to the original name
- Sort field and direction are no longer temporary view state. They persist independently per Emby server and user, then restore after changing folders or tabs and after relaunching the app

### Changed

- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (117)] - 2026-11-21

### Fixed

- Added a persistent red "Delete Server" button to each Emby server edit page because the list swipe action was unreliable on device. Confirmation removes only the local URL, user selection and API key, leaving the Emby server untouched

### Changed

- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (116)] - 2026-11-21

### Added

- Added multiple Emby servers with multiple users per server. Settings manages servers, independent API keys and default users, while the Player toolbar switches users grouped by server
- Switching reloads the selected library and remembers the last user for each server. Directories, artwork, pagination and playback reports all retain the same server and user context

### Security

- Each server's API key is stored separately in the device keychain, and removing a server clears only its own credential. Existing single-server settings and credentials migrate automatically with failure fallback

### Fixed

- Local resume entries are isolated by stable server and user identities while remaining compatible with pre-upgrade records. Late responses cannot replace the current library after rapid switching, and completed playback clears both new and legacy resume keys
- Changing a server URL or API key requires user validation again. A changed remote Emby Server ID rebuilds local user identities to prevent resume mismatches

### Changed

- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (115)] - 2026-11-21

### Changed

- Promoted the Emby library to a dedicated Player tab in the bottom navigation and removed the duplicate Emby entry from Shares. Emby server configuration remains available in Settings
- Moved System Logs into Settings → Logs alongside Log Password. Notification details still open logs with their filter applied, while filtering, auto-refresh, line limits and export remain unchanged
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (114)] - 2026-11-21

### Fixed

- Fixed black screens for remote Emby STRM movies and episodes. Emby's proxy could return a 70-byte JSON error when the upstream required a User-Agent. Remote HTTP media now plays directly through VLCKit with redirect resolution, while local File media continues to use Emby streams

### Added

- Added name, date added, premiere date and community rating sorting with ascending/descending order to Emby directories, using server-side sorting and preserving the selection across pagination

### Changed

- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (113)] - 2026-11-21

### Added

- Added a native Emby player with server validation, API-key authentication and Emby user selection in Settings, plus direct library access from the Shares toolbar menu
- Browse libraries, movie folders and series/seasons/episodes with pagination and artwork. Original-stream Direct Play reuses the existing VLCKit audio, subtitle, rate, fullscreen and lock controls
- Load resume positions from Emby or the device and report playback start, progress and stop events to Emby

### Security

- Emby API keys are stored only in the device keychain and are excluded from configuration backups and project files

### Changed

- Emby server-side transcoding is not included. Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (112)] - 2026-11-21

### Fixed

- Restored standard left-aligned large titles in Containers and Shares and replaced the unreliable title hit area with dedicated toolbar dropdown buttons. Switching between Containers / Compose / VMs and Shares / Disks is verified through UI interaction tests

### Changed

- Logs now places Refresh in the toolbar and Auto refresh / Jump to top / Alerts in an adjacent options menu, removing the persistent horizontal control row. Filtering, source and size information, line limits, export, and alert banners are preserved
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (111)] - 2026-11-21

### Changed

- Replaced the persistent segmented controls in Containers and Shares with navigation-title menus, reclaiming vertical space for lists and search. Containers switches between Containers / Compose / VMs, while Shares switches between Shares / Disks
- Added a visible dropdown indicator while preserving search, sorting, refresh, batch actions, and existing navigation
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (110)] - 2026-11-21

### Fixed

- Added the current video track's nominal bitrate to the player control summary. Only an explicitly supplied track value is shown; missing values are omitted rather than replaced with input or Demux aggregate bitrate
- Shortened VLCKit's verbose codec descriptions for readability, for example `H264 - MPEG-4 AVC (PART 10)` now appears as H.264 and `MPEG AAC AUDIO` as AAC
- Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (109)] - 2026-11-21

### Added

- The player controls now show a compact media summary above the lower-left progress area: video resolution, codec and frame rate, plus the current audio track's codec, channels, language and nominal bitrate. Missing fields are omitted, audio changes update the summary, and input/Demux aggregate bitrate is never presented as audio bitrate
- Added playback-control locking with two-step unlocking. Locking hides and blocks progress, playback, settings and close controls; tapping the video reveals only an unlock button, which hides again after five seconds of inactivity

### Changed

- The media summary uses a lightweight cache populated after playback starts and refreshed only when tracks change, rather than parsing on every progress update. Native app only; Web, Docker and compose-api are unchanged, with no install-script rerun needed

## [TestFlight 2.0.0 (108)] - 2026-11-21

### Added

- The native player controls now include media details showing video codec, profile/level, nominal bitrate, resolution, frame rate, audio/subtitle tracks, metadata, and live playback statistics, with the selected tracks highlighted
- The details panel supports half/full-screen presentation and moves into the playback settings menu on narrow screens. Statistics are read only while the panel is visible, and stale data is cleared on source changes or stop

### Changed

- Upgraded MobileVLCKit from 3.7.3 to the official stable 3.7.5 release with upstream security and crash fixes
- VLCKit 4.0's Dolby Vision Profile 5 support is not included, so MKV P5 color issues remain pending future playback-engine support. Web, Docker, and compose-api are unchanged

## [1.5.1] - 2026-11-21

### Fixed

- Automatic icons now support `file://` PNG/JPEG/WebP images in user Docker templates, fixing Unraid Mobile and other containers whose existing template icons were ignored
- Local icons are restricted to Unraid's standard `docker-icons` directories, validated by path, size, and actual image format, imported under content-derived hashes, and served through the existing API-key-authenticated endpoint without exposing host paths to Web or the app
- compose-api is updated, so **re-run the install script after upgrading**. Web and the existing TestFlight 2.0.0 (107) build use this fix automatically; no new native build is required

## [1.5.0] - 2026-11-21

### Added

- Web and native iOS now show application icons in Docker and Compose lists and details, using exact matches from user templates, community templates, Community Applications, and Dashboard Icons
- Compose stacks favor matching primary containers or services. Multi-service stacks share an automatically resolved icon only when every member resolves to the same value, preventing auxiliary Redis/Postgres containers from representing the whole stack
- Web Docker/Compose details now support custom HTTPS icons, local PNG/JPEG/WebP uploads up to 2 MB, and restoration of automatic matching. Settings persist on Unraid and sync to the app without changing templates or Compose files or recreating containers

### Security

- Uploads validate Base64, size, and magic bytes, use content-derived SHA-256 filenames, and are written atomically with mode 0600 for both configuration and image files
- Uploaded images are served only through strict local references with API-key authentication, MIME/size limits, and defensive response headers. External URLs never receive the API key

### Changed

- Icon enhancement and image failures no longer break Docker or Compose lists. Native snapshots remain backward compatible, and stale image responses are isolated across server or configuration changes
- compose-api is updated, so **re-run the install script after upgrading**. Native changes ship in TestFlight 2.0.0 (107)

## [1.4.9] - 2026-11-20

### Added

- Native iOS adds Pro parameter editing for standalone Docker containers through their Unraid user templates, covering basic settings, ports, paths, variables, devices, labels, and advanced runtime options
- Container edits run as recoverable background jobs with revision conflict protection, masked-secret preservation, original running-state retention, rollback, and extra-network restoration

### Changed

- Parameter edits use the official `xmlToCommand` path and reuse existing local images without pulling or upgrading them. Compose members, containers without user templates, Tailscale-specific containers, and renaming remain excluded
- compose-api is updated, so **re-run the install script after upgrading**. Native changes ship in TestFlight 2.0.0 (104)

## [1.4.8] - 2026-11-19

### Added

- Added free read-only Web text preview with safe plain-text rendering, a 1 MB limit, and UTF-8/UTF-16/GB18030 decoding. Expanded text support to NFO, common subtitles, lyrics, playlists, Dockerfile, and Makefile
- Expanded native previews with additional image, audio, and VLC video formats, plus RTF and Office documents through QuickLook; Web and native now recognize JFIF images and M4B audio

### Fixed

- Web video decode failures now show a clear message with a download fallback, and native QuickLook reports unsupported documents instead of leaving a blank preview

## [1.4.7] - 2026-11-19

### Fixed

- Upgrades from deployments that only have bcrypt hashes can now use empty placeholder secret files without resetting existing DAV/syslog passwords. Fresh installations with empty secrets and no existing credentials still fail closed
- The release test derives the expected bootstrap version from package.json, and re-running a release with an unchanged package version is now idempotent

## [1.4.6] - 2026-11-19

### Added

- Improved media previews, added Chinese subtitle rendering, and expanded file-type icons across Web and native iOS
- Added a separate host bootstrap that validates GraphQL and the API key before invoking the existing idempotent Pro agent installer

### Changed

- Consolidated deployment into one Compose file, one application image, and one long-running unprivileged container; removed the setup/router images, services, and release paths
- Retired multi-server mode across Web, Capacitor, and SwiftUI. Configuration backup and restore now retain only the active server while remaining compatible with legacy backup fields
- The main container now initializes bcrypt credentials and preserves existing settings without host privileges. The free version needs no third-party plugins; the Pro host agent requires only Compose Manager Plus and skips it when already installed

### Security

- Legacy Web multi-server migration now selects only an entry matching the current origin. Ambiguous credentials and sensitive backup uploads fail closed until the user explicitly confirms the connection

## [1.4.5] - 2026-11-18

### Added

- Add a persistent license activation service for first-activation server binding. New auto-bind Pro keys bind atomically to the current server's hashed Unraid flash GUID, accept repeated activation on the same server, and reject activation on other servers
- Web and native iOS now confirm the central binding before enabling Pro and retain only hashed binding data for matched-server offline recovery

### Changed

- The license issuer now generates only auto-bind keys, supports anonymous customer identifiers and per-key device limits, and appends complete keys to a local Git-ignored registry
- New Web and native iOS clients reject legacy pre-bound and unbound license payloads even when their signatures are valid; previously installed app versions must be upgraded to enforce this policy

### Security

- The activation service receives and stores only SHA-256 key and flash-GUID hashes. The signing private key, complete issued keys, raw GUIDs, and customer email addresses are not deployed to it

## [1.4.4] - 2026-11-16

### Fixed

- The Web alert popover heading now shows the unread count instead of the total alert count, so its number decreases together with the bell badge when alerts are marked read

## [1.4.3] - 2026-11-16

### Changed

- The Web alert badge now counts unread items: opening an alert marks it read and decrements the badge, while "Mark all read" clears the count without removing the alert history. Read state persists locally per server

## [1.4.2] - 2026-11-16

### Added

- Notification details now include a "Related logs" section: summary notifications such as Fix Common Problems, whose body only says where to look, inline the actual error lines from syslog (keywords derived per notification, OR-matched, newest first, fetched independently of the log page's maxLines cap), with one tap into the log page pre-filtered. Web alert popover offers the same entry via `/logs?filter=...`
- Notifications can be copied in full (title/subject/body/level/timestamp/link) for pasting into forum posts

### Fixed

- Notifications no longer push users out to a browser: native detail view and Web alert popover show the full body in place (no truncation, HTML tags stripped, line breaks preserved), and cards no longer deep-link wholesale into the WebGUI. "Open in WebUI" is now a secondary entry labeled as requiring login
- Native notification links now resolve relative/absolute URLs against the server address, fixing double slashes and malformed URLs from `base + link` concatenation
- Web `SERVER_META_QUERY` now fetches `description` so the top-bar alert popover can show the body at all; native alert/warning rows expand their body by default and the row icon now signals details

## [1.4.1] - 2026-11-15

### Changed

- Single-server Compose no longer starts or depends on the router; Web multi-server forwarding is opt-in through the `multi-server` profile. Nginx resolves the optional router on demand so its absence cannot block Web startup

## [1.4.0] - 2026-11-15

### Added

- Full-featured Compose now uses `setup + Web + router`: a one-shot privileged setup installs and verifies the host agent while Web stays unprivileged. The controlled router forwards GraphQL, WebSocket, Compose, files, DAV, and syslog only for administrator-registered `routeId + appUrl` pairs
- Web multi-server support adds per-server DAV/syslog credentials and isolates configuration, query caches, and cold-start seeds by target. Container updates safely fall back to GraphQL when compose-api is absent or temporarily unavailable

### Security

- Unknown routes, arbitrary upstreams, duplicate addresses, and missing credentials now fail closed. Fixed stale responses, stats streams, caches, transfers, and destructive confirmations crossing servers during switches, plus DAV rewriting for Host values with explicit ports

## [1.3.33] - 2026-11-15

### Changed

- CA installs no longer forge `$_POST` and include Unraid's `CreateDocker.php`. The CA template XML is edited in place and written as a `my-<name>.xml` user template, then created through the official `update_container` script — the same path already used for container updates, with a manual `docker pull` first for progress logs. The install now requires a writable user-template directory and `update_container`; `CreateDocker.php` is no longer needed

## [1.3.32] - 2026-11-15

### Added

- New compose-api `GET ?action=capabilities` endpoint reports Unraid/PHP versions, api.php hashes for the boot copy and the live copy, the boot-restore error log, and availability of 11 Unraid-internal dependencies. The Debug page gains a host-capabilities card that degrades affected features instead of failing at runtime, and `scripts/verify-host-compat.sh` runs the same checks on the host
- The install script supports `--local-only` so OS-upgrade recovery never downloads the older pinned release over local fixes, and download checksums can now be read from `compose-api/SHA256SUMS`

### Changed

- The boot-restore hook no longer fails silently: it creates the target directory, logs failures to `restore-error.log`, and uses begin/end markers with range deletion so repeated installs stay idempotent without touching other `go` lines

### Fixed

- deploy-stable.sh resolves the real remote path with `readlink -f` before rsync, because Unraid 7.4 ships rsync 3.5, which refuses to follow the nobody-owned `/mnt/user/appdata` symlink to `../cache/appdata`

## [1.3.31] - 2026-11-15

### Changed

- Native Dashboard now condenses CPU, GPU, memory, network, disk, and Parity cards to core percentages or network RX/TX rates with lightweight trends. Models, thermals, capacities, per-disk details, and Parity controls move into expandable details. Native changes ship in TestFlight 2.0.0 (87)

### Fixed

- File-index status and the index builder now detect stale build locks by checking both the PID and process command, clean up only abandoned lock and temporary files, and recover after crashes or reboots without interrupting active builds or touching the formal index. compose-api and the index builder are updated, so **re-run the install script after upgrading**

## [1.3.30] - 2026-11-07

### Added

- Native plugin details now include update notes. During update checks, compose-api extracts and caches newly added `CHANGES` entries from the updated `.plg`, with an explicit message when the author provides no notes. **Re-run the install script after upgrading**. Native changes ship in TestFlight 2.0.0 (85)

### Fixed

- Manually refreshing Containers now dismisses completed update-all and batch-update result panels while preserving progress and logs for running tasks
- Dashboard falls back to local version-only metadata when auxiliary GraphQL fields fail, avoiding transient page errors during unraid-api restarts or Mothership interruptions. Authentication, network, and core-query failures remain visible

## [1.3.29] - 2026-11-01

### Changed

- Replaces the app icon across all clients with an orange circular background, a bold dark U, and array status lights. The original Unraid-style bars are removed for better small-size recognition and visual distinction. Native changes ship in TestFlight 2.0.0 (82)

## [1.3.28] - 2026-10-31

### Added

- Native Dashboard adds live RAPL-based CPU power, System/Docker/VM/Cache memory breakdowns, and Intel/NVIDIA GPU cards with utilization, model, frequency, power, and engine details. Native changes ship in TestFlight 2.0.0 (65)
- Dashboard cards support persistent drag ordering, menu-based movement, and 80%–100% resizing
- compose-api adds the read-only `systemmetrics` endpoint, reading RAPL, memory cgroups, and GPU drivers without querying disk temperatures or waking sleeping disks. **Re-run the install script after upgrading**

### Changed

- Native network rates now use KB as the smallest unit and consistently display zero as `0 KB/s`, replacing the system formatter's `Zero bytes`

## [1.3.27] - 2026-10-31

### Changed

- Single and batch deletion on Web and native iOS now use direct `DELETE`. The five-second undo, trash writes, and restoration flow are removed, and confirmations explicitly state that deletion is irreversible. Native changes ship in TestFlight 2.0.0 (64)

### Fixed

- File search now excludes `.trash` and all its contents in both the app and backend. Existing trash entries disappear from old indexes, while realtime search and future index builds exclude them as well. compose-api and the index builder are updated, so **re-run the install script and rebuild the file index after upgrading**

## [1.3.26] - 2026-10-30

### Fixed

- Fixed missing trailing slashes on soft-delete destinations when batch-deleting folders on Web, keeping deletion and undo consistent with single-folder and native operations
- File and folder name searches no longer omit paths deeper than eight levels. Native also preserves query delimiters such as `&`, `+`, and `#` correctly; these changes ship in TestFlight 2.0.0 (61)
- Disk wake commands now run in parallel, with slow disks explicitly left pending. Fixed CA template numeric targets, official translation loading, same-URL installation recovery without a container, and final running-state validation. compose-api and the index builder are updated, so **re-run the install script and rebuild the file index after upgrading**

## [1.3.25] - 2026-10-27

### Added

- Web and native iOS add Community Applications browsing, search, categories, and details, plus HTTPS `.plg` plugin installation and official Docker template installation. Template URLs must match the current official feed; only new containers are allowed, and masked template defaults never reach the frontend
- Both clients add read-only/correcting Parity checks, pause, resume, cancel, and live progress, plus Pro reboot/shutdown actions with two-step confirmations. The backend uses an operation allowlist and auditing, and explicitly reports an unknown outcome when acceptance cannot be confirmed
- Both clients preview images, videos, and PDFs. Native uses QuickLook, on-demand thumbnails, and a background upload center with a persistent queue, a fixed background session, and cold-start task reconciliation
- Native adds an optional App Lock using Face ID, Touch ID, or the device passcode, with immediate privacy covering in the background. Native changes ship in TestFlight 2.0.0 (38)

### Fixed

- Fixed recurring Compose update badges caused by stale remote digests in the supplemental cache. Pull/rebuild now synchronizes both caches, with locking to prevent scans from overwriting updates
- Fixed stale disk snapshots overriding successful wake results. Explicit wake results take precedence, while post-action refresh, request generations, cross-server isolation, and asynchronous job reattachment are handled consistently
- Improved state consistency across notifications, logs, file indexing, configuration backup, upload completion, and license device identity. compose-api is updated, so **re-run the install script after upgrading**

## [1.3.24] - 2026-10-26

### Fixed

- Corrected Wake and Refresh targeting and messaging: every assigned, healthy sleeping array disk is included, covering data and parity disks across HDDs and SSDs. Unassigned or disabled disks and array-external cache/boot devices remain excluded. Sleeping SSDs are no longer skipped with a misleading no-target message. compose-api is updated, so **re-run the install script after upgrading**. Native changes ship in TestFlight 2.0.0 (37)

## [1.3.23] - 2026-10-26

### Added

- Web and native iOS add an explicit Wake and Refresh action. Normal refresh reads Unraid's cached data without actively spinning up disks; wake only targets sleeping array HDD data disks through official `emcmd cmdSpinup`, with a 30-second total wait and per-disk results. compose-api adds `diskwake` with strict disk selection, locking, preconditions, and auditing. **Re-run the install script after upgrading**

### Changed

- Native containers, VMs, Compose, files, notifications, and plugins now consistently open details for item management. Row ellipsis menus, long-press menus, and swipe actions were removed while multi-select, batch operations, and sorting/filtering toolbars remain. Native changes ship in TestFlight 2.0.0 (36)

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
