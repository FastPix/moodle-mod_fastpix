# mod_fastpix

A Moodle activity that lets teachers add a FastPix-hosted video to any course, tracks how much each student actually watches, and writes completion + grade automatically.

> **Version:** v1.0.0 · **Requires:** Moodle 4.5 LTS+, PHP 8.1+, `local_fastpix` v1.0.0+ · **Licence:** GPL-3.0+

> 📘 **Full setup & usage guide:** [fastpix.com/docs/moodle](https://fastpix.com/docs/moodle).

---

## What it is

`mod_fastpix` is the **student-facing activity** of the FastPix-for-Moodle suite. It's what teachers add to a course (`+ Add an activity or resource → FastPix Video`), what students click to watch, and what writes results to the gradebook when they're done.

Under the hood it consumes `local_fastpix` for everything FastPix-related (credentials, gateway, JWT signing, webhooks). `mod_fastpix` itself owns the activity edit form, the player view, the progress tracker, the six fraud checks, completion, and backup/restore.

If `local_fastpix` is the wiring, `mod_fastpix` is the light switch.

---

## What teachers get

- **Two ways to add a video** — drag-and-drop upload, or paste a URL and FastPix fetches it.
- **Per-video completion threshold** — set what percentage of the video a student must watch to complete (default 90%).
- **Disable seeking** — for compliance / assessment videos, students must watch through; no skipping ahead.
- **Show captions by default** — accessible by default; learners can still toggle off in the player.
- **Real progress reporting** — the gradebook shows actual watch coverage, not just "video played".

## What students get

- **Modern adaptive player** — works on every modern browser, on mobile, on slow networks.
- **Two progress bars** under the video:
  - **Position** — where the playhead is right now.
  - **Coverage** — how many seconds they have actually watched.
- **Resume after refresh** — closing the tab and coming back picks up exactly where they left off.
- **Captions on demand** — every video has a CC button; defaults set by the teacher.
- **One activity, one click** — no separate login, no third-party player.

## What admins get

- **Six-layer fraud detection** — every progress callback runs server-side checks (timeline tampering, simulated playback, capability loss). Failed checks are logged with a typed reason.
- **Backup / restore** — activities round-trip cleanly through Moodle's course backup; asset references survive copies and recycle-bin actions.
- **GDPR privacy provider** — data export and delete work from Moodle's standard data-request UI.
- **No new infrastructure** — uses Moodle's existing cron, gradebook, completion, and capability systems.

---

## How completion works

Coverage drives completion, not playback position. Two reasons:
1. A student can drag the timeline to 100% in one second — that's not learning.
2. Re-watching the same minute three times shouldn't count as three minutes watched.

So the plugin tracks **which seconds the student actually watched** (sorted, deduplicated, merged). When the sum of unique seconds crosses your threshold (default 90%) of the video duration, the activity completes and the grade is written.

Re-watching counts once. Skipping ahead counts as zero. Pausing is free. The numbers reflect time actually spent watching the content.

---

## What this plugin does NOT do

- ❌ Can't be installed standalone. Requires `local_fastpix` v1.0.0+ to be installed first.
- ❌ Doesn't talk to FastPix directly. Every FastPix call goes through `local_fastpix`.
- ❌ Doesn't host video bytes. Those live on FastPix.
- ❌ Doesn't survive a course restored onto a Moodle pointing at a different FastPix account — videos show "Video unavailable" by design.
- ❌ Doesn't add a HTML editor button or filter (those are future plugins).

---

## How to install

Plan for **10 minutes**, assuming `local_fastpix` is already installed and connected.

### Before you start
- A Moodle site you can log into as **Site administrator**.
- **`local_fastpix` v1.0.0+ already installed and connected** to a FastPix account. See `local/fastpix/PRODUCT.md` if you haven't done this yet.
- The plugin ZIP: `mod_fastpix-v1.0.0.zip` from your provider or GitHub Releases.
- A short test video (MP4, under 100 MB).

### Step 1. Install the plugin
1. Log into Moodle as Site administrator.
2. **Site administration → Plugins → Install plugins**.
3. Drag `mod_fastpix-v1.0.0.zip` onto the drop-zone.
4. Click **Install plugin from the ZIP file** → **Continue** through validation.
5. On the "Plugins requiring attention" screen, click **Upgrade Moodle database now**.
6. Wait for the green **Success** tick → **Continue**.

✅ *Verify:* **Site administration → Plugins → Activity modules → Manage activities** lists **FastPix Video** with the green FastPix logo.

### Step 2. Add a test activity
1. Open any course (create a sandbox course if you don't have one).
2. Turn **edit mode** on.
3. **Add an activity or resource → FastPix Video**.

### Step 3. Fill in the activity form
1. **Name** — e.g. "Week 1 Welcome Video".
2. **Description** — optional.
3. **Video source** — drag your test MP4 onto the drop-zone, or paste a URL.
4. **Playback options** (optional):
   - Tick **Disable seeking** to block forward jumps.
   - Tick **Show captions by default** if your video has captions and you want them visible on load.
5. **Activity completion** (optional but recommended):
   - **Completion tracking** → **Show activity as complete when conditions are met**.
   - Tick **Watched at least…%** and set a threshold (default 90).
6. **Save and display.**

### Step 4. Wait for processing
FastPix transcodes the video for streaming, usually under a minute. You'll see a "Video is processing" message that auto-refreshes. When ready, the player appears.

### Step 5. Test as a student
1. Log in as a student (or use **Switch role to → Student**).
2. Open the activity. The player loads and starts.
3. Watch the **Position** and **Coverage** bars under the player.
4. Watch through to the completion threshold. The bar turns green and the activity is marked complete.
5. Check the gradebook — the grade has been written automatically.

---

## Install checklist

- [ ] `local_fastpix` is installed and shows the green "Connected" badge
- [ ] `mod_fastpix` ZIP installed without errors
- [ ] Upgrade screen showed green Success
- [ ] **FastPix Video** appears in the activity chooser with the green FastPix logo
- [ ] Added a FastPix Video activity to a test course
- [ ] Uploaded a test MP4
- [ ] Saw the player load + the two progress bars
- [ ] Watched past the completion threshold → green tick → grade in gradebook

8 ticks = `mod_fastpix` is healthy.

---

## Common questions

**Q: Can teachers upload without involving the admin?**
A: Yes. Once `local_fastpix` is connected, any user with the `mod/fastpix:uploadmedia` capability (editing teachers by default) can upload from their own course.

**Q: What if a student switches devices mid-video?**
A: Watch progress is persisted server-side every 10 seconds and on tab-close. When the student opens the video again — on the same or a different device — they resume from where they left off, and the coverage they've already earned counts.

**Q: What does the player do offline?**
A: Playback stops; watch-progress callbacks queue in browser localStorage and retry when the connection returns. No progress is lost as long as the tab is still open when connectivity is restored.

**Q: Are there abuse / cheating protections?**
A: Yes — six server-side fraud checks run on every progress callback:
1. Watched time can't exceed video length.
2. Watched time can't exceed wall-clock time since session start.
3. Coverage can't decrease.
4. Single-tick gains can't exceed elapsed time.
5. Capability is re-verified every callback.
6. Seek-on-no-skip activities reject forward seeks.

Failed checks increment a per-attempt fraud counter and log a typed reason. The teacher's gradebook view (with the right capability) shows the badge.

**Q: Can a teacher manually override a grade?**
A: Yes. The `mod/fastpix:graderoverride` capability allows manual gradebook editing in the standard Moodle way. The activity's own automatic write happens exactly once, on the 0→1 completion transition; overrides aren't fought over.

**Q: How does it handle backup / restore?**
A: The activity is backed up with its settings and (optionally) per-user attempt rows. The video reference (`fastpix_asset_id`) is preserved. Restoring on the same Moodle (same FastPix account) plays normally. Restoring on a different account shows "Video unavailable" — by design, since the video lives on a tenant that target Moodle can't reach.

**Q: Is the source auditable?**
A: Yes. GPL-3.0. All architectural decisions are in `.claude/` and reference back to `02-mod-fastpix.md`.

**Q: What if I uninstall it?**
A: Course content and Moodle users are untouched. The plugin's tables (`mdl_fastpix`, `mdl_fastpix_attempt`) are removed. Videos on FastPix are not deleted. To remove videos from FastPix, do it from the FastPix dashboard.

---

## What's coming next

| Release | Highlight |
|---|---|
| **v1.0** *(now)* | Activity module, two-bar UI, six fraud checks, completion + gradebook, backup/restore, GDPR |
| v1.1 | Per-activity accent colour, allow-forward-block-backward seek policy |
| v1.2 | Teacher analytics view — coverage heat-map, drop-off points |
| v2.0 | Live captions in the player |

---

## More information

- Full setup & usage guide: [fastpix.com/docs/moodle](https://fastpix.com/docs/moodle)
- The foundation plugin: `local/fastpix/PRODUCT.md`
- Architecture: `mod/fastpix/.claude/`
- Project status & known limitations: [STATUS.md](STATUS.md)
- Release notes: [CHANGELOG.md](CHANGELOG.md)
- Licence: [LICENSE](LICENSE)
- FastPix documentation: [fastpix.com/docs](https://fastpix.com/docs)
