# Changelog

All notable changes to `mod_fastpix` are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows Moodle's `YYYYMMDDNN` convention with a human-readable `release` tag.

---


## [v1.1.0] — 2026-06-15

Feature release: media-protection + caption settings on the activity form,
teacher watch analytics, and asset reference counting — plus a course-context
upload fix that restores teacher uploads.

### Added

**Media settings (mod_form.php)**
- New **Protection & captions** section on the activity form.
- **Access policy** — Private / Public / DRM segmented control (default Private). DRM is only selectable when the site has DRM configured.
- **Captions & transcript** — a toggle with two modes: **Auto-generate** (language picker: `en`/`es`/`it`/`pt`/`de`/`fr`, plus beta languages) or **Upload .vtt** (a drag-and-drop dropzone backed by the new `mod_fastpix/captions_upload` AMD module, uploading to a Moodle draft area).
- Three new columns on `mdl_fastpix` — `access_policy`, `captions_mode`, `language_code` — with a `db/upgrade.php` step. These feed `local_fastpix`'s `create_upload_session` at upload time (the title is taken from the activity name).

**Watch reports / analytics (report.php)**
- A **Watch report** secondary-navigation tab on the activity, gated by `mod/fastpix:viewallattempts`.
- **Per-video report** — unique viewers, average watched %, completion rate, biggest drop-off, an engagement curve (inline SVG with a fixed 0–100 axis), and a per-student table (watch %, watch time, milestones, completed, last position, seeks, fraud flags).
- **Per-user report** — one student's engagement across every FastPix video in the course.
- **CSV export** on both reports via `\core\dataformat`.
- New `\mod_fastpix\report\watch_report` aggregation service, renderer methods, and `report_video` / `report_user` templates. Display-only — no new tracking, no FastPix calls.

**Asset reference counting**
- `mod_fastpix` now registers a reference with `local_fastpix` (`mod_fastpix:<activityid>`) when an activity links a video, and releases it on delete or asset-swap, through `asset_lifecycle_service`. `local_fastpix` soft-deletes the asset only when its last reference is released. The calls are fail-safe — a missing or throwing service never blocks a save or delete.

### Changed

**Upload context (mod_form.php + amd/src/upload_widget.js)**
- The upload widget now receives the **course** context id instead of the system context, and forwards `contextid` to `local_fastpix`'s `create_upload_session` / `create_url_pull_session`. Upload permission (`mod/fastpix:uploadmedia`) is enforced at the course context, and uploads are tagged to their course.
- `create_upload_session` arguments updated to the new contract (`contextid`, `title`, `accesspolicy`, `captionsmode`, `languagecode`) and its no-underscore return fields (`uploadurl` / `uploadid`).
- `local_fastpix` dependency pinned to `2026061500`; `release` is now `1.1.0`.

**JavaScript served locally — no CDN (Moodle Plugins Directory requirement)**
- Player libraries are no longer loaded from a public CDN. `hls.js` (1.6.16) and the `@fastpix/resumable-uploads` SDK (1.0.5) are now vendored under `thirdparty/` and served from the Moodle site; both are declared in the new `thirdpartylibs.xml`.
- The FastPix web player is served from `local_fastpix` (its build embeds FastPix API endpoint literals, so it cannot live here per architecture rule A3). `mod_fastpix` consumes its URL via `\local_fastpix\service\playback_service::player_lib_url()` — hence the dependency bump to `2026061500`.

### Fixed
- **Per-user watch report could resolve any site user by id.** `report.php` now restricts the per-user report target to users enrolled in the course (`mod/fastpix:viewallattempts` holders only), closing a fullname-disclosure path on both the HTML and CSV outputs.
- **Course backup dropped three media settings.** `access_policy`, `captions_mode`, and `language_code` were not captured by the activity backup and silently reset to defaults on restore/duplicate; they are now included.
- **Teachers could not upload from the activity form.** Upload permission was checked at the *system* context — where editing teachers hold no role — instead of the course context, so every upload was denied. It is now checked at the course context; students enrolled in the course still cannot upload or embed but can view.
- **"Invalid parameter value detected" on upload.** The widget was sending stale arguments (`filename`, `size`) to `create_upload_session`; it now sends the required `title` / `accesspolicy` / `captionsmode` / `languagecode`, and reads the `uploadurl` / `uploadid` return fields.
- **Engagement chart auto-scaled** instead of showing a fixed 0–100 range (Moodle's chart API passes the bound as Chart.js v2 `ticks.min/max`, which the bundled Chart.js v4 ignores). Replaced with an inline SVG locked to 0–100.
- **The "Watch report" link never rendered** — the navigation callback short-circuited on `empty($PAGE->cm)`, which is always true for that magic property (`moodle_page` has `__get` but no `__isset` for `cm`).

---

## [v1.0.0] — 2026-05-14

First production release.

### Added

**Activity module skeleton**
- `lib.php` with the seven required Moodle callbacks (`fastpix_add_instance`, `fastpix_update_instance`, `fastpix_delete_instance`, `fastpix_supports`, `fastpix_get_coursemodule_info`, `fastpix_grade_item_update`, `fastpix_update_grades`).
- `db/install.xml` defining two tables (`fastpix`, `fastpix_attempt`).
- `db/access.php` with five capabilities — `addinstance`, `view`, `viewallattempts`, `graderoverride`, `uploadmedia`.

**Activity form (mod_form.php)**
- Drag-and-drop upload widget driven by AMD (`mod_fastpix/upload_widget`).
- URL-pull path — paste a URL, FastPix fetches it server-side.
- Asset-swap guard — blocks changing the video once any student has watch progress.
- Per-activity completion threshold field.
- Two playback toggles — **Disable seeking**, **Show captions by default**.
- Card-based UI with brand colours for the playback options section.

**Player view (view.php + view.mustache)**
- FastPix Web Player (`@fastpix/fp-player@1.0.17`) mounted via native ESM import to bypass Moodle's RequireJS conflict with hls.js UMD.
- Two-bar progress UI under the player — **Position** (current playhead) and **Coverage** (unique seconds watched).
- Resume support — playback restarts from the last persisted position.
- Captions auto-enabled when the teacher ticks "Show captions by default"; falls back to tenant default.
- Processing-state page with meta-refresh + JS poller while the asset is being transcoded.

**Watch tracker (inline in view.php)**
- Self-contained tracker; no AMD race against the player mount.
- 10-second heartbeat with `core/ajax`; pagehide / visibilitychange flush via `navigator.sendBeacon`.
- Interval merge on the client (sorted, non-overlapping); same merge runs server-side.
- Immediate persist on the 0→1 completion transition so completion and grade write within milliseconds.
- LocalStorage fallback queue when the server is unreachable.

**Six fraud checks (watch_tracker_service)**
1. `exceeds_duration` — watched > asset duration.
2. `exceeds_wall_clock` — watched > (now − session start) + 10s tolerance.
3. `regression` — claimed coverage < server-persisted coverage.
4. `implausible_gain` — single-tick gain > elapsed time + 10s tolerance.
5. `capability_lost` — `mod/fastpix:view` revoked mid-session.
6. `seek_on_noskip` — forward seek on activities with `no_skip_required = 1`.

All six run on every callback; each violation increments `fraud_count` and records a typed reason. The 10-second tolerance is fixed; changes require an ADR.

**External web services**
- `mod_fastpix_record_view_progress` — accepts watch intervals + position + seek count; runs fraud checks; updates attempt row; triggers completion + grade on transition.
- `mod_fastpix_refresh_playback_token` — re-mints a playback JWT before expiry without rebuilding the attempt.

**Custom completion**
- One rule: `completionwatchedpercent`.
- Sticky once granted — teachers can raise the threshold without retroactively revoking student completions.
- Wired through `fastpix_get_coursemodule_info` + `fastpix_get_completion_active_rule_descriptions` for the activity-completion-rules UI.

**Gradebook integration**
- `fastpix_grade_item_update` / `fastpix_update_grades` callbacks per Moodle convention (M1 bare-name prefix).
- Grade written exactly once per attempt on the 0→1 completion transition via `grade_update()`. Subsequent callbacks past the threshold are idempotent — no re-write.

**Milestone events**
- `\mod_fastpix\event\watch_milestone` fires once per `(user, activity, milestone)` pair at 25 / 50 / 75 / 100% coverage.
- Idempotency enforced by `milestone_*_at` columns + transactional set-and-fire.

**Privacy provider (S10)**
- Full `\core_privacy\local\metadata\provider` + `request\plugin\provider` + `core_userlist_provider`.
- Declares every PII column on `mdl_fastpix_attempt`.
- `session_token` is redacted from data exports; deletes purge attempt rows cleanly.

**Backup / restore (M9)**
- Activity row + per-user attempt rows backed up; `session_token` omitted (auth material).
- Restore preserves `fastpix_asset_id` verbatim — cross-account restore shows "Video unavailable" by design (ADR-010).
- Session tokens minted fresh on first view of the restored activity.

**Activity icon**
- Brand-coloured FastPix logo as `pix/icon.svg` and `pix/monologo.svg`.
- CSS in `styles.css` overrides Moodle 4.x's purpose-tint mask so the green brand shows through.

### Architecture

- Three-layer architecture: endpoint (`classes/external/*`, `view.php`, `mod_form.php`) → service (`classes/service/*`) → `local_fastpix` consumer.
- Zero direct calls to `local_fastpix\api\gateway`, `local_fastpix\service\jwt_signing_service`, or `local_fastpix\webhook\*` — only the four documented consumer services (`asset_service`, `playback_service`, `upload_service`, `feature_flag_service`).
- Zero HTTP calls of any kind from inside `mod/fastpix/` (CI-verified).
- Zero `fastpix.io` literals anywhere in `mod/fastpix/` (CI-verified).

### Dependencies

- Moodle 4.5 LTS or newer (`$plugin->requires = 2024100700`).
- PHP 8.1+.
- `local_fastpix` v1.0.0 or newer (`$plugin->dependencies = ['local_fastpix' => 2026050801]`).

### Known limitations

- Cross-FastPix-account restore is intentionally unsupported — assets stay with the FastPix tenant that owns them (ADR-010).
- Widevine L3 screen-recording is unmitigated in v1.0 — same constraint as every browser-based DRM in 2026 (S7).
- Reconciler for missed webhooks is deferred to year 2 (ADR-003).
- Per-user watermarking withdrawn (ADR-005).

---

## [v0.x.0-dev] — 2026-05-08 to 2026-05-13

Pre-release development cycle. Five phases (A → E) shipped in sequence:

- **Phase A** — Skeleton: lib.php, install.xml, lang strings, capabilities, version.php.
- **Phase B** — Activity form: upload widget, URL pull, validation, asset-swap guard.
- **Phase C** — Player view: ESM player mount, hls.js loader, processing state, error states.
- **Phase D** — Watch tracking: six fraud checks, completion, gradebook, two-bar UI, resume, milestone events.
- **Phase E** — Backup/restore + real privacy provider.

The complete per-phase development log lives in `.claude/` alongside the architectural rules.

---

[v1.1.0]: https://github.com/FastPix/moodle-mod_fastpix/releases/tag/v1.1.0
[v1.0.0]: https://github.com/FastPix/moodle-mod_fastpix/releases/tag/v1.0.0
