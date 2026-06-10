# Status: v1.0.0 — feature-complete, pre-pilot

`mod_fastpix` is **feature-complete** against the design doc's engineering
phases (§3, Phases A–F). The full teacher→student→gradebook loop works:
add a video, watch it, cross the threshold, get completion + grade. What
remains before a v1.0.0 GA tag is **pilot validation** (Phase G) and
**Plugins Directory approval** (Phase H) — process, not code.

- **114/114 PHPUnit tests passing, 284 assertions.**
- **Behat suite present** — `add_activity`, `student_view`,
  `no_skip_enforcement`, `completion_grade` (the four the design doc names).
- **Architecture guards grep-clean** — no hard-coded FastPix domain or API
  host literals (A3), zero HTTP calls (A2), no direct gateway/jwt/webhook refs
  (A4/CC1), no direct `local_fastpix_*` or `grade_grades` writes (A5/CG1),
  session-token verification on every progress callback (S3).
- **CI** — `.github/workflows/moodle-plugin-ci.yml` runs phplint, phpcpd,
  phpmd, codechecker, validate, savepoints, mustache, PHPUnit, and Behat on
  PHP 8.1/8.2/8.3 × MOODLE_405_STABLE, with `local_fastpix` checked out as a
  dependency.

Maturity: `MATURITY_STABLE`, release `1.1.0`, version `2026061010`.
Depends on `local_fastpix` >= `2026061009`.

## v1.1.0 additions

- **Media settings** on the activity form — access policy (Private / Public /
  DRM) and captions (auto-generate in a chosen language, or upload a `.vtt`),
  stored on `mdl_fastpix` and consumed by `local_fastpix`'s
  `create_upload_session` at upload time.
- **Watch report** — teacher analytics gated by `mod/fastpix:viewallattempts`:
  per-video (summary + engagement curve + per-student table) and per-user
  views, with CSV export. Display-only over `mdl_fastpix_attempt`.
- **Asset reference counting** — registers/releases a `local_fastpix`
  reference (`mod_fastpix:<activityid>`) on link / delete / asset-swap; the
  asset is soft-deleted only at zero references. Fail-safe.
- **Upload uses the course context** — the upload widget now passes the course
  context id and forwards `contextid` to `local_fastpix`, so
  `mod/fastpix:uploadmedia` is enforced at the course (fixing the
  "teachers can't upload" regression). Uploads are tagged to their course.

## What works

### Foundation (Phase A)
- Activity registered under **Assessment** purpose; installs cleanly on
  Moodle 4.5.
- 3-layer architecture (endpoint → service → `local_fastpix` consumer)
  enforced (A1). Services hold all business logic; endpoints only do the
  auth dance and delegate.
- Schema in `db/install.xml`: `mdl_fastpix` + `mdl_fastpix_attempt` with the
  documented `UNIQUE(user_id, activity_id)` and milestone-timestamp columns.
- Five capabilities (M3): `addinstance`, `view`, `viewallattempts`,
  `graderoverride`, `uploadmedia` (the last per ADR-012).
- HMAC `session_secret` auto-bootstrapped on install (`db/install.php`, S1) —
  never stored in plaintext code.

### Activity edit form (Phase B)
- Single Video-source panel: drag-and-drop **chunked upload** (FastPix
  resumable SDK via `local_fastpix_create_upload_session`) or **paste URL**
  (`local_fastpix_create_url_pull_session`).
- Playback options persist: **Disable seeking** (no-skip, drives fraud
  check #6) and **Show captions by default**.
- Server-side validation (M10): rejects empty source, malformed URL
  (delegates to `local_fastpix` SSRF guard), out-of-range threshold; forbids
  asset swap on an activity that already has attempts (D5).

### Student playback (Phase C)
- `view.php` renders `<fastpix-player>` with `playback-id` + `token`
  (DRM JWT, TTL 300s), resumes from last position, sticky "Completed"
  indicator.
- **In-place processing → player swap** — `processing_state_poller` polls the
  `mod_fastpix_get_player_state` web service and mounts the player without a
  full-page reload (an improvement over the doc's 30s `get_upload_status`
  poll).
- Token refresh via `mod_fastpix_refresh_playback_token` (re-checks
  capability + session token + attempt state, CC6).
- Processing / "Video unavailable" / DRM-unsupported states all rendered.

### Watch tracking, completion & grade (Phase D)
- `watch_tracker.js` POSTs progress every 10s to
  `mod_fastpix_record_view_progress`.
- **All six fraud checks** run in order, every callback (S4); each violation
  increments `fraud_count` with a typed reason; 10s tolerance is the abuse
  ceiling.
- One custom completion rule, `completionwatchedpercent` (CG3) — renders as a
  native Moodle completion condition.
- Completion + grade transition together; grade written **only** through
  `grade_update()` on the transition to complete, exactly once (CG1, CG4).
- `watch_milestone` event fires once per 25/50/75/100% (CG5).

### Backup, restore & GDPR (Phase E)
- `backup/moodle2/*` preserves the `fastpix_id` reference, not asset bytes
  (M9/BR1). Cross-FastPix-account restore shows "Video unavailable" per
  ADR-010 — this is the contract, not a bug.
- Privacy provider declares every PII column in `mdl_fastpix_attempt` (S10);
  `delete_data_for_user` / `export_user_data` / `get_users_in_context` all
  round-trip.
- Recycle-bin hook soft-deletes the asset via `asset_lifecycle_service`.

## What's left for GA (Phases G–H — not code)

- 100-attempt reconciliation ≥ 99.5% across 3 design-partner sites for 14
  consecutive days (DoD #5).
- Setup-time p50 ≤ 20 min confirmed on partner installs (DoD #6).
- Zero P0/P1 bugs through the pilot (DoD #7).
- Approved listing in the Moodle Plugins Directory (DoD #9) and the public
  `v1.0.0` tag (DoD #10).

## Known limitations

- **Widevine L3 screen recording is unmitigated in v1.0** (S7 / design doc
  §11.3 T2). Desktop browsers on L3 can screen-record DRM content. Year-2
  escalation is server-side burn-in — out of scope here. Documented, not
  fixed.
- **Cross-FastPix-account restore shows "Video unavailable"** (ADR-010).
  Backups carry the asset reference, not the media; restoring onto a
  different FastPix account cannot resolve the asset. Intentional.
- **Private assets need a `playback_id`** to play. If a private/DRM asset is
  ready but its `playback_id` is still null, playback can't resolve — this is
  a `local_fastpix` webhook concern (sibling plugin, A4), not `mod_fastpix`.

## Out of scope (per ADRs)

- No reconciler task (ADR-003, deferred to year 2).
- No per-user dynamic watermark / burn-in (ADR-005, withdrawn).
- No additional completion rules beyond `completionwatchedpercent` (CG3).
- No direct FastPix calls — everything routes through `local_fastpix`
  services (A2/A4).
