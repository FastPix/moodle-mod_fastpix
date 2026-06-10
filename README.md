# mod_fastpix

A Moodle activity module that lets teachers add a
[FastPix](https://www.fastpix.com)-hosted video to any course, tracks how
much of each video a student actually watches, and writes activity
completion and grades automatically. It builds on `local_fastpix`, the
foundation plugin that provides the FastPix credentials, HTTP gateway,
webhook ingestion, and playback-token signing.

Use this plugin if you run a Moodle site with `local_fastpix` already
connected and you want teachers to embed FastPix videos as graded,
completion-tracked activities. `mod_fastpix` never contacts FastPix
directly; every video operation goes through `local_fastpix`. On its own,
it adds the **FastPix Video** activity type, the player view, the watch
tracker, completion and gradebook integration, and backup/restore.

## Features

### Video authoring

- Two ways to add a video: drag-and-drop upload, or paste a direct URL
  that FastPix fetches.
- Resumable chunked uploads with a live progress bar, so large files
  survive an unreliable connection.
- URL-pull sources are validated by the `local_fastpix` SSRF guard.
- **Protection & captions** per activity: choose an access policy
  (Private / Public / DRM) and a captions mode — auto-generate in a
  chosen language, or upload your own WebVTT (`.vtt`) subtitle file.
  These are applied to the video when it is uploaded to FastPix.

### Playback experience

- Modern adaptive player that works across browsers, mobile, and slow
  networks.
- Watch progress is saved server-side every 10 seconds and on tab close,
  so a student resumes where they left off on any device.
- A **Preparing your video** card is shown while a freshly uploaded video
  is transcoding; it swaps to the player automatically when the video is
  ready.
- Optional captions on by default, with a per-activity toggle.

### Completion and grading

- Per-activity completion threshold based on watch coverage, not playhead
  position (default 90%).
- Completion is driven by the unique seconds a student watched, merged and
  deduplicated, so dragging the timeline forward or re-watching the same
  minute does not inflate progress.
- The grade is written once, at full marks, through Moodle's standard
  grade API when the student crosses the threshold.

### Watch tracking and integrity

- Six server-side fraud checks run on every progress callback, covering
  timeline tampering, simulated playback, and capability loss.
- Failed checks increment a per-attempt counter and record a typed reason;
  they do not silently block the student.
- An optional **Disable seeking** mode rejects forward seeks for
  compliance and assessment videos.

### Reporting and analytics

- A **Watch report** for teachers (capability `mod/fastpix:viewallattempts`),
  reachable from a tab on the activity.
- Per-video view: unique viewers, average watched %, completion rate, the
  biggest drop-off point, an engagement curve, and a per-student table.
- Per-user view: one student's engagement across every FastPix video in the
  course.
- CSV export on both. The report is read-only — it surfaces the watch data
  already recorded, with no extra tracking and no calls to FastPix.

### Backup, restore, and privacy

- Full support for Moodle's standard course backup and restore, including
  per-user attempt rows; asset references are preserved.
- Full Moodle Privacy API support, including per-user export and deletion
  under GDPR.

## Requirements

- Moodle 4.5 LTS or later.
- PHP 8.1 or later (tested through PHP 8.3).
- `local_fastpix` v1.0.0 or later, installed and connected to a FastPix
  account, with a green **Authenticated** result on its **Test
  connection** button. [Set up the foundation plugin](https://fastpix.com/docs/moodle/local-plugin)
  first if you haven't.
- A short test video (MP4, under 100 MB) for verification.

### Supported databases

The plugin works with any database server supported by Moodle:

| Database | Minimum version |
|---|---|
| MariaDB | 10.6.7 |
| MySQL | 8.0 |
| PostgreSQL | 13 |
| MS SQL Server | 2017 |
| Oracle | 19c |

> **Note:** Oracle support is deprecated in Moodle. If you're starting a
> new deployment, pick one of the other databases.

## Install

Choose one of the following methods.

### Install from the Moodle Plugins directory

1. Sign in to your Moodle site as an administrator.
2. Go to **Site administration > Plugins > Install plugins**.
3. Search for **FastPix Video** and follow the prompts.

### Install from a ZIP file

1. Download the latest release from the **Download** button on this Moodle
   plugins directory page, or from the GitHub Releases page.
2. Sign in to your Moodle site as an administrator.
3. Go to **Site administration > Plugins > Install plugins** and upload
   the ZIP file. Don't unzip it first; Moodle installs the package
   directly from the ZIP.
4. Select **Install plugin from the ZIP file**, then continue through the
   validation screen.
5. On the **Plugins requiring attention** screen, select **Upgrade Moodle
   database now**.
6. When the upgrade finishes, select **Continue**.

The install creates two database tables (`mdl_fastpix` and
`mdl_fastpix_attempt`) and registers the **FastPix Video** activity type.
There are no Composer dependencies at runtime.

> **Note:** `mod_fastpix` cannot be installed standalone. Moodle blocks the
> install with a dependency error until `local_fastpix` is present. If you
> haven't set up the foundation plugin yet, do that first, see
> [Set up local plugin](https://fastpix.com/docs/moodle/local-plugin).

To confirm the install, go to **Site administration > Plugins > Activity
modules > Manage activities**. **FastPix Video** appears in the list with
the FastPix logo.

## Usage

Teachers add videos directly from a course; no further admin action is
needed once `local_fastpix` is connected.

### Add a video activity

1. Open a course and turn **Edit mode** on.
2. Select **Add an activity or resource**, then choose **FastPix Video**.
3. Enter a **Name** and an optional **Description**.
4. Add the video in the **Video source** section (see below).
5. Set **Playback options**, **Activity completion**, and **Grade** as
   needed.
6. Select **Save and display**.

> **Important:** The video is uploaded to FastPix when you save the
> activity, not while you are still filling in the form. If you choose a
> file and then leave without saving, nothing is uploaded.

### Upload a video

In the **Video source** section, drag a file onto the drop zone or select
it from your device. When the progress bar reaches 100%, select **Save and
display** to commit. Uploading requires the `mod/fastpix:uploadmedia`
capability, which editing teachers hold by default; it is checked at the
**course** context, and the upload is tagged to that course. Students
enrolled in the course can view the video but cannot upload or embed.

### Pull from a URL

Instead of uploading, paste a direct video URL into the **Video URL** field
and select **Upload**. FastPix fetches the file from that URL. Malformed or
unreachable URLs are rejected by the `local_fastpix` SSRF guard.

### Playback options

In the **Playback options** section, set how the player behaves. Both
settings are optional and apply only to this activity.

- **Disable seeking**: blocks forward seeks during playback so students
  must watch through. Backward seeks are still allowed.
- **Show captions by default**: turns captions on when the player loads.
  Learners can still toggle them off.

### Completion and grade

In the **Activity completion** section, set **Completion tracking** to
**Show activity as complete when conditions are met**, tick **Students must
watch the video**, and set the percentage (default 90%). In the **Grade**
section, set the maximum grade (default 100). When a student crosses the
threshold, the activity is marked complete and the grade is written once.

## Watch tracking and anti-cheating

The player posts watch progress to Moodle every 10 seconds. Each callback
runs six server-side fraud checks, in order:

1. Watched time cannot exceed the video duration.
2. Watched time cannot exceed wall-clock time since the session started
   (10-second tolerance).
3. Coverage cannot decrease.
4. A single callback's gain cannot exceed the elapsed time (10-second
   tolerance).
5. The student's view capability is re-verified on every callback.
6. On **Disable seeking** activities, any forward seek is rejected.

A failed check increments a per-attempt fraud counter and records a typed
reason. When the counter passes 20, the gradebook shows a badge (visible
with `mod/fastpix:viewallattempts`) so a teacher can review. Correction is
a human decision, made with `mod/fastpix:graderoverride`.

## Backup and restore

`mod_fastpix` supports Moodle's standard course backup and restore. A
backup captures the activity settings and per-user attempt rows, and
preserves the asset reference, not the video bytes, which stay on FastPix.

- Restoring onto the same Moodle (same FastPix account) plays normally.
- Restoring onto a Moodle pointing at a different FastPix account shows
  **Video unavailable**, because the asset doesn't exist in the new
  account. This is expected behaviour, not a bug.

Deleting an activity (including via the recycle bin) soft-deletes the
underlying asset through `local_fastpix`.

## Activity settings reference

| Setting | Description | Default |
|---|---|---|
| **Video source** | Upload a file or pull from a URL through `local_fastpix`. | Required |
| **Disable seeking** | Block forward seeks during playback. Backward seeks remain allowed. | Disabled |
| **Show captions by default** | Turn captions on when the player loads. | Disabled |
| **Students must watch the video** | Completion condition: minimum watch coverage to mark the activity complete. | 90% |
| **Grade** | Maximum grade written when the student completes the activity. | 100 |

## Capabilities

| Capability | Description | Default role |
|---|---|---|
| `mod/fastpix:addinstance` | Add a FastPix Video activity to a course. | Editing teacher, Manager |
| `mod/fastpix:view` | View and play a FastPix Video activity. | All enrolled roles |
| `mod/fastpix:uploadmedia` | Upload a file or submit a URL for the activity's video. | Editing teacher |
| `mod/fastpix:viewallattempts` | View per-student watch attempts and fraud badges. | Teacher, Manager |
| `mod/fastpix:graderoverride` | Manually override the automatically written grade. | Teacher, Manager |

The foundation capability `local/fastpix:configurecredentials` is defined
by the `local_fastpix` plugin, not this one.

## Privacy

This plugin includes a full Moodle Privacy API provider. It declares every
personal-data column in the attempt table (watch progress, seek count,
fraud count, completion state, and session timestamps). Per-user export and
deletion requests under GDPR are handled from Moodle's standard
data-request UI.

For full details after install, see **Site administration > Users >
Privacy and policies > Data registry** in your Moodle site.

## Support

- File an issue on the
  [issue tracker](https://github.com/FastPix/moodle-mod_fastpix/issues).
- Read the [integration guide](https://fastpix.com/docs/moodle/activity-plugin)
  for installation and usage walkthroughs.
- Set up the [foundation plugin](https://fastpix.com/docs/moodle/local-plugin)
  if you haven't already.
- Read the [changelog](https://github.com/FastPix/moodle-mod_fastpix/blob/main/CHANGELOG.md)
  for release notes.

## License

Copyright © 2026 FastPix Inc. Released under the
[GNU GPL v3.0 or later](https://www.gnu.org/licenses/gpl-3.0.html). For the
full license text, see [`LICENSE`](https://github.com/FastPix/moodle-mod_fastpix/blob/main/LICENSE).
